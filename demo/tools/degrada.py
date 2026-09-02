"""Trasforma un PDF nitido nella scansione sciatta dello stesso documento.

Serve a produrre documenti su cui Textract dichiara una confidenza bassa: e'
l'unica leva che manda in revisione un documento con tutti i campi chiave
presenti, perche' il punteggio in quel caso coincide con la confidenza OCR.

Il PDF risultante non ha piu' uno strato di testo: e' una sequenza di immagini,
esattamente come la scansione di un foglio di carta.

Uso:
    python demo/tools/degrada.py ingresso.pdf uscita.pdf [--intensita media]
"""

import argparse
import io
import random
import subprocess
import sys
import tempfile
from pathlib import Path

from PIL import Image, ImageEnhance, ImageFilter

# Ogni livello descrive una fotocopia peggiore della precedente.
#
# La confidenza che Textract dichiara non scende in proporzione al degrado: fra
# "lieve" e "media" quasi non si muove, poi crolla. I due livelli in mezzo sono
# stati aggiunti proprio per coprire la fascia fra 70 e 90, che con i soli tre
# estremi restava vuota. I valori misurati sono annotati in demo/README.md.
INTENSITA = {
    "lieve": dict(dpi=200, sfocatura=0.6, rumore=6, rotazione=0.4, contrasto=0.92, qualita=70),
    "media": dict(dpi=150, sfocatura=1.1, rumore=14, rotazione=0.9, contrasto=0.80, qualita=50),
    "sensibile": dict(dpi=132, sfocatura=1.35, rumore=18, rotazione=1.1, contrasto=0.75, qualita=42),
    "marcata": dict(dpi=120, sfocatura=1.5, rumore=20, rotazione=1.3, contrasto=0.72, qualita=36),
    "forte": dict(dpi=110, sfocatura=1.7, rumore=22, rotazione=1.5, contrasto=0.68, qualita=32),
}


def pagine_da_pdf(sorgente: Path, dpi: int, lavoro: Path) -> list[Path]:
    """Rasterizza il PDF in PNG, una immagine per pagina."""
    prefisso = lavoro / "pagina"
    subprocess.run(
        ["pdftoppm", "-r", str(dpi), "-png", str(sorgente), str(prefisso)],
        check=True,
        capture_output=True,
    )
    pagine = sorted(lavoro.glob("pagina*.png"))
    if not pagine:
        raise RuntimeError(f"pdftoppm non ha prodotto pagine da {sorgente}")
    return pagine


def sporca(immagine: Image.Image, cfg: dict, rnd: random.Random) -> Image.Image:
    """Applica i difetti tipici di una scansione fatta male."""
    pagina = immagine.convert("L")

    # Il foglio non entra mai perfettamente dritto nello scanner.
    angolo = rnd.uniform(-cfg["rotazione"], cfg["rotazione"])
    pagina = pagina.rotate(angolo, resample=Image.BICUBIC, expand=False, fillcolor=245)

    # Ottica sporca o messa a fuoco approssimativa.
    pagina = pagina.filter(ImageFilter.GaussianBlur(cfg["sfocatura"]))

    # Inchiostro stanco e carta ingiallita: il contrasto cala.
    pagina = ImageEnhance.Contrast(pagina).enhance(cfg["contrasto"])
    pagina = ImageEnhance.Brightness(pagina).enhance(1.04)

    # Grana del sensore.
    pixel = pagina.load()
    larghezza, altezza = pagina.size
    ampiezza = cfg["rumore"]
    for y in range(altezza):
        for x in range(larghezza):
            valore = pixel[x, y] + rnd.randint(-ampiezza, ampiezza)
            pixel[x, y] = 0 if valore < 0 else 255 if valore > 255 else valore

    # Compressione JPEG aggressiva: introduce gli aloni attorno ai caratteri.
    buffer = io.BytesIO()
    pagina.convert("RGB").save(buffer, format="JPEG", quality=cfg["qualita"])
    buffer.seek(0)
    return Image.open(buffer).convert("RGB")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("sorgente", type=Path)
    parser.add_argument("destinazione", type=Path)
    parser.add_argument("--intensita", choices=sorted(INTENSITA), default="media")
    parser.add_argument("--seme", type=int, default=20260820)
    argomenti = parser.parse_args()

    cfg = INTENSITA[argomenti.intensita]
    rnd = random.Random(argomenti.seme)

    with tempfile.TemporaryDirectory() as temporanea:
        lavoro = Path(temporanea)
        pagine = pagine_da_pdf(argomenti.sorgente, cfg["dpi"], lavoro)
        sporcate = [sporca(Image.open(p), cfg, rnd) for p in pagine]

    argomenti.destinazione.parent.mkdir(parents=True, exist_ok=True)
    sporcate[0].save(
        argomenti.destinazione,
        format="PDF",
        save_all=True,
        append_images=sporcate[1:],
        resolution=cfg["dpi"],
    )

    peso = argomenti.destinazione.stat().st_size / 1024
    print(
        f"  {argomenti.destinazione.name}: {len(sporcate)} pagine, "
        f"intensita' {argomenti.intensita}, {peso:.1f} KB"
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
