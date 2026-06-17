# Backup/Restore Locale PostgreSQL

Questa procedura e' dimostrativa per la PoC locale. Non e' PITR, non gestisce retention automatica e non sostituisce una strategia di backup production.

## Backup

```bash
make backup-local
```

Il dump viene scritto in `backups/local/poc-YYYYmmdd-HHMMSS.sql` usando `pg_dump --clean --if-exists` dentro il container `postgres`. La cartella `backups/` e' esclusa dal versionamento.

## Restore

```bash
make restore-local BACKUP=backups/local/poc-YYYYmmdd-HHMMSS.sql
```

Il restore applica il dump con `psql -v ON_ERROR_STOP=1` sul database locale configurato nel container. Il dump e' generato con `--clean --if-exists`, quindi droppa e ricrea gli oggetti da solo: si puo' ripristinare direttamente sopra un database gia' migrato, senza passaggi preliminari.

## Oggetti Documentali

Il target copre solo PostgreSQL. I PDF originali e split vivono nello storage S3-compatible locale o reale in base a `POC_DOCUMENT_DISK`; per una ricostruzione completa vanno preservati anche bucket/prefix documentali coerenti con i path salvati a database.

## Verifica Manuale

1. Eseguire `make backup-local`.
2. Annotare il file creato in `backups/local`.
3. Sporcare o azzerare i dati (es. `make fresh`).
4. Eseguire `make restore-local BACKUP=<file>`.
5. Aprire la SPA e verificare `/api/v1/state`: i dati precedenti al reset devono essere tornati.
