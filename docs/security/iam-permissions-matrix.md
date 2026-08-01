# Matrice dei permessi IAM

Questa matrice è una **proposta derivata dalla MVP** secondo il principio del privilegio minimo:
**non è una policy IAM di produzione definitiva**. In LocalStack i permessi non sono applicati
realmente; servono come riferimento per il passaggio ad AWS reale.

| Componente | Azione IAM | Risorsa | Motivo | Ambiente | Note |
| --- | --- | --- | --- | --- | --- |
| API Laravel | `s3:PutObject` | Bucket/prefix documenti reale | Salvare il documento caricato per OCR/AI | Smoke AWS reale / futura prod | Preferire prefix scoped per tenant/ambiente. |
| API Laravel | `s3:GetObject` | Bucket/prefix documenti reale | Leggere il file originale come input Bedrock | Smoke AWS reale / futura prod | Necessario quando l'elaborazione legge dal disco S3. |
| Deploy SPA (CI / `make frontend-s3-local-upload`) | `s3:PutObject` / `s3:DeleteObject` / `s3:ListBucket` | Bucket frontend statico (`FRONTEND_STATIC_BUCKET`) | Caricare/sincronizzare la build Angular (`apps/frontend/dist`) | LocalStack / futura prod | Bucket dedicato alla SPA, separato dai documenti. In prod aggiungere `cloudfront:CreateInvalidation` per invalidare `index.html` dopo il deploy. |
| Serving SPA (CDN/edge → S3) | `s3:GetObject` | Oggetti del bucket frontend statico | Servire gli asset della SPA Angular | LocalStack / futura prod | In LocalStack il bucket è **public-read** (`Principal: "*"`) solo per l'emulatore CDN locale (Nginx). In prod: bucket **privato** con CloudFront **OAC** (principal `cloudfront.amazonaws.com`, condizione `AWS:SourceArn` sulla distribuzione), **mai** `Principal: "*"`. |
| API Laravel | `states:StartExecution` | State machine della pipeline documentale | Avviare il workflow documentale | LocalStack / futura prod | In LocalStack usa ruolo/risorse create da Terraform. |
| API Laravel | `states:StartExecution` | State machine della pipeline comunicazioni | Avviare la generazione della comunicazione | LocalStack / futura prod | State machine distinta da quella documentale. |
| API Laravel | `s3:GetObject` | Prefix copertine (`communications/covers/`) | Servire la copertina all'operatore in streaming | LocalStack / futura prod | Nessun URL presigned: il controllo tenant resta applicativo. |
| API Laravel | `s3:PutObject` / `s3:DeleteObject` | Prefix copertine (`communications/covers/`) | Sostituire e rimuovere manualmente la copertina | LocalStack / futura prod | La sostituzione scrive una chiave nuova e cancella la precedente. |
| Worker queue | `sqs:ReceiveMessage` | Code dei task documentali e comunicazioni | Consumare i task con callback token | LocalStack / futura prod | Ogni worker legge solo la propria coda. |
| Worker queue | `sqs:DeleteMessage` | Code dei task documentali e comunicazioni | Confermare il task completato/segnalato | LocalStack / futura prod | Eliminare solo dopo la decisione di callback. |
| Worker queue | `sqs:GetQueueAttributes` | Code e DLQ di entrambe le pipeline | Readiness e diagnostica | LocalStack / futura prod | Usato da health check e da `mvp:dlq:list --queue=<pipeline>`. |
| Worker callback | `states:SendTaskSuccess` | Callback token Step Functions | Riprendere il task riuscito | LocalStack / futura prod | Scoped per state machine/esecuzione dove supportato. |
| Worker callback | `states:SendTaskFailure` | Callback token Step Functions | Riprendere il ramo di fallimento | LocalStack / futura prod | Necessario per la gestione esplicita degli errori. |
| Worker callback | `states:SendTaskHeartbeat` | Callback token Step Functions | Mantenere vivo il task lungo (Textract, Bedrock testo e immagini) | LocalStack / futura prod | Evita il timeout di stato per i task lunghi. |
| Worker OCR | `textract:StartDocumentTextDetection` | `*` o risorsa scoped supportata | Avviare l'OCR asincrono | Solo AWS reale | Lo scoping risorsa di Textract è limitato per alcune API. |
| Worker OCR | `textract:GetDocumentTextDetection` | `*` o risorsa scoped supportata | Recuperare il risultato OCR | Solo AWS reale | Validare con il policy simulator dell'account target. |
| Worker AI | `bedrock:Converse` | Modello testo o inference profile selezionato | Split/estrazione/generazione testo | Solo AWS reale | L'accesso al modello dipende da account/regione. |
| Worker AI | `bedrock:InvokeModel` | Modello immagini (`BEDROCK_IMAGE_MODEL_ID`) | Generare la copertina della comunicazione | Solo AWS reale | Senza accesso, la copertina degrada con motivo esplicito e la comunicazione resta valida. |
| Worker AI | `s3:PutObject` | Prefix copertine (`communications/covers/`) | Salvare la copertina generata | LocalStack / futura prod | Il record conserva solo il path relativo al disco. |
| Config loader | `ssm:GetParameter` / `ssm:GetParametersByPath` | Path SSM della MVP | Caricare la configurazione runtime | LocalStack / futura prod | Sola lettura. |
| Config loader | `secretsmanager:GetSecretValue` | Secret runtime | Caricare i segreti | LocalStack / futura prod | Sola lettura; nessun permesso di list necessario. |
| CI smoke AWS | `sts:AssumeRoleWithWebIdentity` | Ruolo fornito dall'azienda | Smoke OIDC | GitHub Actions (manuale) | Nessuna credenziale AWS statica in CI. |
