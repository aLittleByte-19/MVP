<?php

namespace App\Copilot\Ai;

use App\Exceptions\Copilot\InvalidAiOutputException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;

/**
 * Treats every model response as untrusted input: the decoded payload is
 * checked against a JSON Schema (resources/schemas/ai) plus the semantic
 * rules a schema cannot express, before anything reaches persistence.
 */
class AiOutputValidator
{
    /**
     * @return array<int, array{employee_name: string, start_page: int, end_page: int}>
     *
     * @throws InvalidAiOutputException
     */
    public function validateSplitDocument(mixed $decoded): array
    {
        $this->validateAgainstSchema('split-document', 'splitDocument', $decoded);

        /** @var array<int, array{employee_name: string, start_page: int, end_page: int}> $decoded */
        $errors = [];
        $seenRanges = [];

        foreach ($decoded as $index => $segment) {
            if ($segment['start_page'] > $segment['end_page']) {
                $errors[] = "segments[{$index}]: start_page maggiore di end_page";
            }

            $range = $segment['start_page'].'-'.$segment['end_page'];

            if (isset($seenRanges[$range])) {
                $errors[] = "segments[{$index}]: intervallo di pagine duplicato ({$range})";
            }

            $seenRanges[$range] = true;
        }

        if ($errors !== []) {
            throw new InvalidAiOutputException('splitDocument', $errors);
        }

        return array_values(array_map(static fn (array $segment): array => [
            'employee_name' => trim($segment['employee_name']),
            'start_page' => $segment['start_page'],
            'end_page' => $segment['end_page'],
        ], $decoded));
    }

    /**
     * @return array{employee_first_name: ?string, employee_last_name: ?string, company_name: ?string, document_date: ?string, document_type: ?string, description: ?string, confidence_score: ?int}
     *
     * @throws InvalidAiOutputException
     */
    public function validateExtractFields(mixed $decoded): array
    {
        $this->validateAgainstSchema('extract-fields', 'extractFields', $decoded);

        /** @var array<string, mixed> $decoded */
        $date = $decoded['document_date'] ?? null;

        if (is_string($date)) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

            if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                throw new InvalidAiOutputException('extractFields', ['document_date: data inesistente nel calendario']);
            }
        }

        $string = static fn (string $key): ?string => isset($decoded[$key]) && is_string($decoded[$key]) && trim($decoded[$key]) !== ''
            ? trim($decoded[$key])
            : null;

        return [
            'employee_first_name' => $string('employee_first_name'),
            'employee_last_name' => $string('employee_last_name'),
            'company_name' => $string('company_name'),
            'document_date' => $string('document_date'),
            'document_type' => $string('document_type'),
            'description' => $string('description'),
            'confidence_score' => isset($decoded['confidence_score']) && is_int($decoded['confidence_score'])
                ? $decoded['confidence_score']
                : null,
        ];
    }

    /**
     * @return array{title: string, body: string}
     *
     * @throws InvalidAiOutputException
     */
    public function validateGenerateCommunication(mixed $decoded): array
    {
        $this->validateAgainstSchema('generate-communication', 'generateCommunication', $decoded);

        /** @var array{title: string, body: string} $decoded */
        return [
            'title' => trim($decoded['title']),
            'body' => trim($decoded['body']),
        ];
    }

    /**
     * @throws InvalidAiOutputException
     */
    private function validateAgainstSchema(string $schemaName, string $operation, mixed $decoded): void
    {
        $schemaPath = resource_path("schemas/ai/{$schemaName}.schema.json");
        $schema = json_decode((string) file_get_contents($schemaPath));

        if (! is_object($schema)) {
            throw new \RuntimeException("Schema AI non leggibile: {$schemaPath}");
        }

        $validator = new Validator;
        $validator->setMaxErrors(10);

        $result = $validator->validate(Helper::toJSON($decoded), $schema);

        if ($result->isValid()) {
            return;
        }

        $error = $result->error();
        $messages = $error === null ? ['errore di validazione sconosciuto'] : (new ErrorFormatter)->formatFlat($error);

        throw new InvalidAiOutputException($operation, array_slice(array_map('strval', $messages), 0, 10));
    }
}
