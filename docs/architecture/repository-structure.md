# Repository Structure

The repository keeps Laravel conventions while separating runtime boundaries from domain logic.

- `app/Copilot`: domain-specific application code for the AI co-pilot.
- `app/Copilot/Ai`: Bedrock integration and AI-specific services.
- `app/Copilot/Audit`: audit logging services.
- `app/Copilot/Documents`: document enums and processing services.
- `app/Copilot/Communications`: communication enums and future communication services.
- `app/Copilot/Identity`: resolved runtime user identity.
- `app/Copilot/Observability`: Prometheus exporter and metric recording.
- `app/Copilot/Ocr`: Textract OCR integration.
- `app/Copilot/Workflow`: Step Functions/SQS workflow orchestration services.
- `app/Http`: HTTP controllers, middleware and request validation.
- `app/Models/Copilot`: Eloquent models for the PoC domain.
- `apps/frontend`: React/Vite SPA.
- `openapi/v1`: versioned API contract.
- `infra/localstack`: LocalStack Terraform model for local production-like runs.
- `infra/aws`: placeholder for the future real AWS product baseline.
- `docker`: local runtime images and service configuration.
