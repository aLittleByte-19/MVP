# AWS Infrastructure

This directory is reserved for the real AWS product baseline.

The current MVP provisions AWS-like dependencies through LocalStack in `infra/localstack`; no real AWS resources are created from this directory yet. Add production AWS Terraform only after IAM roles, account boundaries, remote state, secrets handling, and deployment ownership are defined.

## CloudWatch Alarms (reference example, not provisioned)

The metrics emitted by `EmfMetricsRecorder` (see [ADR 0015](../../docs/architecture-decisions/0015-osservabilita-minima-cloudwatch-emf.md)) land in CloudWatch under the `MVP/App` namespace once a real AWS environment routes container logs there. The snippet below is a **documented reference, not applied**: this repo has no real AWS environment connected, so there is nothing to run `terraform apply` against yet. Use it as the starting point when this directory grows real Terraform.

```hcl
# Reference only — not part of any applied Terraform configuration.

resource "aws_cloudwatch_metric_alarm" "high_error_rate" {
  alarm_name          = "mvp-app-high-error-rate"
  namespace           = "MVP/App"
  metric_name         = "Errors"
  statistic           = "Sum"
  period              = 300
  evaluation_periods  = 2
  comparison_operator = "GreaterThanThreshold"
  threshold           = 5
}

resource "aws_cloudwatch_metric_alarm" "high_latency" {
  alarm_name          = "mvp-app-high-latency"
  namespace           = "MVP/App"
  metric_name         = "Latency"
  statistic           = "p99"
  period              = 300
  evaluation_periods   = 3
  comparison_operator = "GreaterThanThreshold"
  threshold           = 2000 # ms
}

resource "aws_cloudwatch_metric_alarm" "dlq_depth" {
  alarm_name          = "mvp-app-dlq-depth"
  namespace           = "MVP/App"
  metric_name         = "DlqDepth"
  statistic           = "Maximum"
  period              = 300
  evaluation_periods  = 1
  comparison_operator = "GreaterThanThreshold"
  threshold           = 0
}
```
