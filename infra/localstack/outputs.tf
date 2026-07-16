output "documents_queue_url" {
  value = aws_sqs_queue.documents.url
}

output "documents_dlq_url" {
  value = aws_sqs_queue.documents_dlq.url
}

output "documents_bucket" {
  value = aws_s3_bucket.documents.bucket
}

output "frontend_static_bucket" {
  value = aws_s3_bucket.frontend_static.bucket
}

output "frontend_s3_website_endpoint" {
  value = "http://${aws_s3_bucket.frontend_static.bucket}.s3-website.localhost.localstack.cloud:4566"
}

output "edge_cdn_local_url" {
  value = var.edge_cdn_local_url
}

output "event_bus_name" {
  value = aws_cloudwatch_event_bus.mvp.name
}

output "state_machine_arn" {
  value = aws_sfn_state_machine.document_pipeline.arn
}

output "runtime_ssm_path" {
  value = local.runtime_ssm_path
}

output "runtime_secret_name" {
  value = aws_secretsmanager_secret.app_runtime.name
}

output "runtime_parameter_names" {
  value = sort([for parameter in aws_ssm_parameter.app_runtime : parameter.name])
}
