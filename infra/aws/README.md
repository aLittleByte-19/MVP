# AWS Infrastructure

This directory is reserved for the real AWS product baseline.

The current MVP provisions AWS-like dependencies through LocalStack in `infra/localstack`; no real AWS resources are created from this directory yet. Add production AWS Terraform only after IAM roles, account boundaries, remote state, secrets handling, and deployment ownership are defined.
