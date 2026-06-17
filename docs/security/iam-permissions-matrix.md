# Matrice dei permessi IAM

Questa matrice è una **proposta derivata dalla PoC** secondo il principio del privilegio minimo:
**non è una policy IAM di produzione definitiva**. In LocalStack i permessi non sono applicati
realmente; servono come riferimento per il passaggio ad AWS reale.

| Componente | Azione IAM | Risorsa | Motivo | Ambiente | Note |
| --- | --- | --- | --- | --- | --- |
| API Laravel | `s3:PutObject` | Bucket/prefix documenti reale | Salvare il documento caricato per OCR/AI | Smoke AWS reale / futura prod | Preferire prefix scoped per tenant/ambiente. |
| API Laravel | `s3:GetObject` | Bucket/prefix documenti reale | Leggere il file originale come input Bedrock | Smoke AWS reale / futura prod | Necessario quando l'elaborazione legge dal disco S3. |
| API Laravel | `states:StartExecution` | State machine della pipeline documentale | Avviare il workflow documentale | LocalStack / futura prod | In LocalStack usa ruolo/risorse create da Terraform. |
| Worker queue | `sqs:ReceiveMessage` | Coda dei task documentali | Consumare i task con callback token | LocalStack / futura prod | Solo coda sorgente. |
| Worker queue | `sqs:DeleteMessage` | Coda dei task documentali | Confermare il task completato/segnalato | LocalStack / futura prod | Eliminare solo dopo la decisione di callback. |
| Worker queue | `sqs:GetQueueAttributes` | Coda dei task documentali/DLQ | Readiness e diagnostica | LocalStack / futura prod | Usato da health check e controlli DLQ. |
| Worker callback | `states:SendTaskSuccess` | Callback token Step Functions | Riprendere il task riuscito | LocalStack / futura prod | Scoped per state machine/esecuzione dove supportato. |
| Worker callback | `states:SendTaskFailure` | Callback token Step Functions | Riprendere il ramo di fallimento | LocalStack / futura prod | Necessario per la gestione esplicita degli errori. |
| Worker callback | `states:SendTaskHeartbeat` | Callback token Step Functions | Mantenere vivo il task lungo (Textract/Bedrock) | LocalStack / futura prod | Evita il timeout di stato per i task lunghi. |
| Worker OCR | `textract:StartDocumentTextDetection` | `*` o risorsa scoped supportata | Avviare l'OCR asincrono | Solo AWS reale | Lo scoping risorsa di Textract è limitato per alcune API. |
| Worker OCR | `textract:GetDocumentTextDetection` | `*` o risorsa scoped supportata | Recuperare il risultato OCR | Solo AWS reale | Validare con il policy simulator dell'account target. |
| Worker AI | `bedrock:InvokeModel` / `bedrock:Converse` | Modello o inference profile selezionato | Split/estrazione/generazione contenuti | Solo AWS reale | L'accesso al modello dipende da account/regione. |
| Config loader | `ssm:GetParameter` / `ssm:GetParametersByPath` | Path SSM della PoC | Caricare la configurazione runtime | LocalStack / futura prod | Sola lettura. |
| Config loader | `secretsmanager:GetSecretValue` | Secret runtime | Caricare i segreti | LocalStack / futura prod | Sola lettura; nessun permesso di list necessario. |
| CI smoke AWS | `sts:AssumeRoleWithWebIdentity` | Ruolo fornito dall'azienda | Smoke OIDC | GitHub Actions (manuale) | Nessuna credenziale AWS statica in CI. |
