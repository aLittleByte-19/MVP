variable "name_prefix" {
  description = "Prefix for LocalStack resources."
  type        = string
  default     = "poc"
}

variable "aws_region" {
  description = "AWS region used by LocalStack and future smoke tests."
  type        = string
  default     = "eu-north-1"
}

variable "localstack_endpoint" {
  description = "LocalStack edge endpoint reachable from Terraform and app containers."
  type        = string
  default     = "http://localstack:4566"
}

variable "ssm_parameter_path" {
  description = "Base SSM Parameter Store path used by the application runtime loader."
  type        = string
  default     = "/poc/app"
}

variable "runtime_secret_name" {
  description = "Secrets Manager secret containing application runtime secrets."
  type        = string
  default     = "/poc/app/runtime"
}

variable "app_url" {
  description = "Public URL used by Laravel for URL generation."
  type        = string
  default     = "https://localhost:8443"
}

variable "db_database" {
  description = "PostgreSQL database name used by the application."
  type        = string
  default     = "poc"
}

variable "db_username" {
  description = "PostgreSQL application user."
  type        = string
  default     = "poc"
}

variable "db_password" {
  description = "PostgreSQL application password stored in Secrets Manager."
  type        = string
  sensitive   = true
  default     = "poc-local-password"
}

variable "bedrock_model_id" {
  description = "Bedrock model or inference profile identifier used by the application."
  type        = string
  default     = "amazon.nova-lite-v1:0"
}

variable "confidence_threshold" {
  description = "Minimum confidence threshold for document extraction review."
  type        = number
  default     = 80
}

variable "local_ses_sender" {
  description = "Local SES sender identity."
  type        = string
  default     = "noreply@alittlebyte.local"
}
