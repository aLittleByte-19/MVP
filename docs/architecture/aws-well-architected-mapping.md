# AWS Well-Architected Mapping

This mapping explains how the current PoC aligns with AWS Well-Architected. It is not a formal AWS review and does not claim production certification.

| Pillar | Current implementation | Remaining production work |
| --- | --- | --- |
| Operational excellence | Docker-first lifecycle, Make targets, LocalStack/Terraform provisioning, release migration job, health/readiness, OTel Collector, Prometheus rules, CI workflows. | Define owned SLOs, on-call policy, production dashboards, incident process, and release approvals. |
| Security | No runtime admin UI, SSM/Secrets split, structured identity middleware, RBAC/ABAC checks, tenant scoping, audit events, blocked legacy routes, no static IAM credentials in ordinary CI. | Replace local identity with enterprise IdP boundary, attach scoped AWS IAM role, add image/SBOM scanning gate, finalize CORS/security headers. |
| Reliability | SQS worker, DLQ resources, explicit failed states, no automatic AI fallbacks, readiness checks, LocalStack smoke path. | Connect Step Functions end-to-end, add idempotency keys for write operations, define retry budgets and DLQ replay runbook. |
| Performance efficiency | SPA served statically by Nginx, PHP-FPM with OPcache, async document pipeline, HTTP latency histogram, bounded request body size. | Move large uploads to presigned S3 finalization and set capacity targets from observed traffic. |
| Cost optimization | Real AWS smoke is optional/manual, local AWS-like resources run in LocalStack, Prometheus is local. | Add cloud cost allocation tags, budgets, object lifecycle policies, and bounded production smoke schedules. |
| Sustainability | Dockerized tooling avoids host drift, multi-stage frontend build, local teardown through Compose/Terraform, minimized real-cloud execution. | Add image size budgets and explicit retention policies for logs, metrics, and documents. |

## Primary References

- AWS Well-Architected Framework: https://docs.aws.amazon.com/wellarchitected/latest/framework/the-pillars-of-the-framework.html
- OpenTelemetry Collector: https://opentelemetry.io/docs/collector/
- Google SRE monitoring: https://sre.google/sre-book/monitoring-distributed-systems/
