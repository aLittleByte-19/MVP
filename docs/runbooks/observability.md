# Observability Runbook

## Local Services

| Service | URL | Purpose |
| --- | --- | --- |
| Prometheus | http://localhost:9090 | Metrics store and alert rule evaluation. |
| Alertmanager | http://localhost:9093 | Local/demo alert routing. |
| Grafana | http://localhost:3000 | Provisioned dashboards. |
| Tempo | http://localhost:3200 | Trace storage queried by Grafana. |
| OTel Collector | internal `otel-collector:4317/4318` | OTLP ingest and Prometheus scraping. |

## Start and Validate

```bash
make observability-config
make observability-up
```

`make observability-config` validates:

- OTel Collector configuration;
- Prometheus configuration and rule files.

## Data Flow

1. Laravel exposes `/internal/metrics` through Nginx only for internal scrapers.
2. OTel Collector scrapes Nginx, Traefik and itself.
3. OTel Collector exports metrics on `:9464`.
4. Prometheus scrapes the Collector exporter and evaluates alert rules.
5. Alertmanager receives alerts from Prometheus.
6. Tempo receives traces from the OTel Collector.
7. Grafana provisions Prometheus and Tempo datasources from file.

## Dashboards

Dashboard JSON lives in `docker/grafana/dashboards`:

- `api-golden-signals.json`
- `document-pipeline.json`
- `queues-and-dlq.json`
- `ai-ocr-quality.json`

Datasource provisioning lives in `docker/grafana/provisioning`.

## Alert Rules

Rules live in `docker/prometheus/rules`:

- `api-alerts.yml`
- `pipeline-alerts.yml`
- `queue-alerts.yml`
- `ai-alerts.yml`

The local Alertmanager receiver is intentionally a demo receiver. Do not configure real email, Slack or paging secrets in this repository.
