locals {
  account_id          = "000000000000"
  localstack_endpoint = trimsuffix(var.localstack_endpoint, "/")
  runtime_ssm_path    = trimsuffix(var.ssm_parameter_path, "/")

  tags = {
    Project     = "poc-document-pipeline"
    Environment = "local"
    ManagedBy   = "terraform"
  }

  app_parameters = {
    APP_NAME                    = "Alittlebyte PoC"
    APP_ENV                     = "production"
    APP_DEBUG                   = "false"
    APP_URL                     = var.app_url
    APP_LOCALE                  = "it"
    APP_FALLBACK_LOCALE         = "it"
    APP_FAKER_LOCALE            = "it_IT"
    LOG_CHANNEL                 = "stderr"
    LOG_LEVEL                   = "info"
    DB_CONNECTION               = "pgsql"
    DB_HOST                     = "postgres"
    DB_PORT                     = "5432"
    DB_DATABASE                 = var.db_database
    DB_USERNAME                 = var.db_username
    DB_SSLMODE                  = "disable"
    REDIS_CLIENT                = "phpredis"
    REDIS_HOST                  = "redis"
    REDIS_PORT                  = "6379"
    CACHE_STORE                 = "redis"
    SESSION_DRIVER              = "redis"
    SESSION_ENCRYPT             = "true"
    SESSION_SECURE_COOKIE       = "true"
    SESSION_SAME_SITE           = "lax"
    QUEUE_CONNECTION            = "sqs"
    QUEUE_FAILED_DRIVER         = "database-uuids"
    SQS_ENDPOINT                = local.localstack_endpoint
    SQS_PREFIX                  = "${local.localstack_endpoint}/${local.account_id}"
    SQS_QUEUE                   = aws_sqs_queue.documents.name
    FILESYSTEM_DISK             = "s3"
    AWS_DEFAULT_REGION          = var.aws_region
    AWS_BUCKET                  = aws_s3_bucket.documents.bucket
    AWS_ENDPOINT                = local.localstack_endpoint
    AWS_USE_PATH_STYLE_ENDPOINT = "true"
    BEDROCK_MODEL_ID            = var.bedrock_model_id
    POC_CONFIDENCE_THRESHOLD    = tostring(var.confidence_threshold)
    DOCUMENT_MAX_UPLOAD_MB      = "25"
    TEXTRACT_ENABLED            = "false"
    TEXTRACT_AWS_REGION         = var.aws_region
    MAIL_MAILER                 = "log"
    MAIL_FROM_ADDRESS           = var.local_ses_sender
    MAIL_FROM_NAME              = "Alittlebyte PoC"
    POC_IDENTITY_MODE           = "local"
    POC_LOCAL_USER_ID           = "poc-local-user"
    POC_LOCAL_USER_EMAIL        = "operator@alittlebyte.local"
    POC_LOCAL_USER_NAME         = "Alittlebyte Operator"
    POC_LOCAL_TENANT_ID         = "poc-local-tenant"
    POC_LOCAL_ROLES             = "poc-operator"
  }

  app_secrets = {
    APP_KEY               = "base64:${base64encode(random_password.app_key.result)}"
    DB_PASSWORD           = var.db_password
    AWS_ACCESS_KEY_ID     = "test"
    AWS_SECRET_ACCESS_KEY = "test"
    AWS_SESSION_TOKEN     = ""
  }
}

resource "random_password" "app_key" {
  length  = 32
  special = false
}

resource "aws_sqs_queue" "documents_dlq" {
  name = "${var.name_prefix}-documents-dlq"
  tags = local.tags
}

resource "aws_sqs_queue" "documents" {
  name                       = "${var.name_prefix}-documents"
  visibility_timeout_seconds = 330
  message_retention_seconds  = 345600

  redrive_policy = jsonencode({
    deadLetterTargetArn = aws_sqs_queue.documents_dlq.arn
    maxReceiveCount     = 3
  })

  tags = local.tags
}

resource "aws_s3_bucket" "documents" {
  bucket        = "${var.name_prefix}-documents-local"
  force_destroy = true
  tags          = local.tags
}

resource "aws_ssm_parameter" "app_runtime" {
  for_each = local.app_parameters

  name  = "${local.runtime_ssm_path}/${each.key}"
  type  = "String"
  value = tostring(each.value)
  tags  = local.tags
}

resource "aws_secretsmanager_secret" "app_runtime" {
  name                    = var.runtime_secret_name
  recovery_window_in_days = 0
  tags                    = local.tags
}

resource "aws_secretsmanager_secret_version" "app_runtime" {
  secret_id     = aws_secretsmanager_secret.app_runtime.id
  secret_string = jsonencode(local.app_secrets)
}

resource "aws_cloudwatch_event_bus" "poc" {
  name = "${var.name_prefix}-events"
  tags = local.tags
}

resource "aws_cloudwatch_event_rule" "pipeline_terminal" {
  name           = "${var.name_prefix}-pipeline-terminal"
  event_bus_name = aws_cloudwatch_event_bus.poc.name

  event_pattern = jsonencode({
    source      = ["poc.documents"]
    detail-type = ["DocumentPipelineCompleted", "DocumentPipelineFailed"]
  })

  tags = local.tags
}

resource "aws_cloudwatch_event_target" "pipeline_terminal_queue" {
  rule           = aws_cloudwatch_event_rule.pipeline_terminal.name
  event_bus_name = aws_cloudwatch_event_bus.poc.name
  arn            = aws_sqs_queue.documents.arn
}

resource "aws_iam_role" "step_functions" {
  name = "${var.name_prefix}-step-functions-role"

  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Effect = "Allow"
        Principal = {
          Service = "states.amazonaws.com"
        }
        Action = "sts:AssumeRole"
      }
    ]
  })

  tags = local.tags
}

resource "aws_sfn_state_machine" "document_pipeline" {
  name       = "${var.name_prefix}-document-pipeline"
  role_arn   = aws_iam_role.step_functions.arn
  definition = file("${path.module}/state-machines/document-pipeline.asl.json")
  tags       = local.tags
}

resource "aws_ses_email_identity" "sender" {
  email = var.local_ses_sender
}
