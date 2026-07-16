variable "name_prefix" {
  description = "Prefix for LocalStack resources."
  type        = string
  default     = "mvp"
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
  default     = "/mvp/app"
}

variable "runtime_secret_name" {
  description = "Secrets Manager secret containing application runtime secrets."
  type        = string
  default     = "/mvp/app/runtime"
}

variable "app_url" {
  description = "Public URL used by Laravel for URL generation."
  type        = string
  default     = "https://localhost:8443"
}

variable "frontend_static_bucket" {
  description = "LocalStack S3 bucket dedicated to Angular static assets."
  type        = string
  default     = "mvp-frontend-static-local"
}

variable "edge_cdn_local_url" {
  description = "Local URL of the Docker CDN/edge emulator (Nginx) that fronts the LocalStack S3 frontend bucket."
  type        = string
  default     = "https://localhost:8443"
}

variable "db_database" {
  description = "PostgreSQL database name used by the application."
  type        = string
  default     = "mvp"
}

variable "db_username" {
  description = "PostgreSQL application user."
  type        = string
  default     = "mvp"
}

variable "db_password" {
  description = "PostgreSQL application password stored in Secrets Manager."
  type        = string
  sensitive   = true
  default     = "mvp-local-password"
}

variable "redis_password" {
  description = "Redis requirepass stored in Secrets Manager."
  type        = string
  sensitive   = true
  default     = "mvp-redis-local-password"
}

variable "bedrock_model_id" {
  description = "Bedrock model or inference profile identifier used by the application."
  type        = string
  default     = "amazon.nova-lite-v1:0"
}

variable "bedrock_region" {
  description = "AWS region used by Bedrock runtime calls."
  type        = string
  default     = "eu-north-1"
}

variable "bedrock_endpoint" {
  description = "Optional Bedrock endpoint override. Leave empty for AWS managed endpoint."
  type        = string
  default     = "not-configured"
}

variable "confidence_threshold" {
  description = "Minimum confidence threshold for document extraction review."
  type        = number
  default     = 80
}

variable "document_disk" {
  description = "Laravel filesystem disk used for uploaded source documents."
  type        = string
  default     = "s3"

  validation {
    condition     = contains(["s3", "real_s3"], var.document_disk)
    error_message = "document_disk must be either s3 or real_s3."
  }
}

variable "real_aws_region" {
  description = "AWS region used by the real S3 bucket for source documents."
  type        = string
  default     = "eu-central-1"
}

variable "real_aws_access_key_id" {
  description = "Optional AWS access key for real S3/Textract/Bedrock smoke tests."
  type        = string
  sensitive   = true
  default     = ""
}

variable "real_aws_secret_access_key" {
  description = "Optional AWS secret key for real S3/Textract/Bedrock smoke tests."
  type        = string
  sensitive   = true
  default     = ""
}

variable "real_aws_session_token" {
  description = "Optional AWS session token for real S3/Textract/Bedrock smoke tests."
  type        = string
  sensitive   = true
  default     = ""
}

variable "real_s3_bucket" {
  description = "Real S3 bucket used by Textract for document OCR."
  type        = string
  default     = "not-configured"
}

variable "real_s3_prefix" {
  description = "Object key prefix for real S3 document uploads."
  type        = string
  default     = "documents/"
}

variable "textract_enabled" {
  description = "Enable real Textract OCR for document workflow tasks."
  type        = bool
  default     = false
}

variable "textract_region" {
  description = "AWS region used by Textract."
  type        = string
  default     = "eu-central-1"
}

variable "textract_max_pages" {
  description = "Optional Textract page limit. Use 0 to disable the limit."
  type        = number
  default     = 0
}

variable "textract_max_bytes" {
  description = "Optional Textract object byte limit. Use 0 to disable the limit."
  type        = number
  default     = 0
}

variable "local_ses_sender" {
  description = "Local SES sender identity."
  type        = string
  default     = "noreply@alittlebyte.local"
}
