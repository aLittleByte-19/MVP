<?php

namespace Tests\Support;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Assert;
use Symfony\Component\Yaml\Yaml;

/**
 * Valida le risposte reali dell'API contro openapi/v1/alittlebyte-mvp-api.yaml.
 *
 * OpenAPI 3.1 usa il dialetto JSON Schema 2020-12: i nodi schema del contratto
 * sono validabili direttamente con opis/json-schema registrando il documento
 * intero, cosi' i $ref interni (#/components/...) si risolvono da soli. Lo
 * schema da applicare viene ricavato navigando paths -> method -> responses,
 * quindi un endpoint non documentato fa fallire il test.
 */
class OpenApiSpec
{
    private const SPEC_ID = 'https://mvp.alittlebyte.local/openapi/alittlebyte-mvp-api.json';

    private static ?object $document = null;

    private static ?Validator $validator = null;

    public static function assertResponseMatchesContract(mixed $json, string $path, string $method, string $status): void
    {
        $pointer = self::responseSchemaPointer($path, $method, $status);
        $result = self::validator()->validate(Helper::toJSON($json), self::SPEC_ID.'#'.$pointer);

        if (! $result->isValid()) {
            $error = $result->error();
            $messages = $error === null ? ['errore di validazione sconosciuto'] : (new ErrorFormatter)->formatFlat($error);

            Assert::fail(sprintf(
                "Risposta %s %s (%s) non conforme allo schema OpenAPI %s:\n - %s",
                strtoupper($method),
                $path,
                $status,
                $pointer,
                implode("\n - ", array_map('strval', $messages)),
            ));
        }

        Assert::assertTrue(true);
    }

    private static function responseSchemaPointer(string $path, string $method, string $status): string
    {
        $operation = self::document()->paths->{$path}->{$method} ?? null;

        if (! is_object($operation)) {
            Assert::fail('Operazione non documentata nel contratto OpenAPI: '.strtoupper($method)." {$path}");
        }

        $response = $operation->responses->{$status} ?? null;

        if (! is_object($response)) {
            Assert::fail("Status {$status} non documentato nel contratto OpenAPI per ".strtoupper($method)." {$path}");
        }

        if (isset($response->{'$ref'})) {
            $response = self::resolvePointer(substr((string) $response->{'$ref'}, 1));
        }

        $schema = $response->content->{'application/json'}->schema ?? null;

        if (! is_object($schema)) {
            Assert::fail('Nessuno schema application/json nel contratto OpenAPI per '.strtoupper($method)." {$path} ({$status})");
        }

        if (isset($schema->{'$ref'})) {
            return substr((string) $schema->{'$ref'}, 1);
        }

        return '/paths/'.self::escapePointerToken($path)."/{$method}/responses/{$status}/content/application~1json/schema";
    }

    private static function resolvePointer(string $pointer): object
    {
        $node = self::document();

        foreach (explode('/', ltrim($pointer, '/')) as $token) {
            $token = str_replace(['~1', '~0'], ['/', '~'], $token);
            $node = $node->{$token} ?? null;

            if ($node === null) {
                Assert::fail("Puntatore JSON non risolvibile nel contratto OpenAPI: {$pointer}");
            }
        }

        if (! is_object($node)) {
            Assert::fail("Il puntatore JSON {$pointer} non individua un oggetto nel contratto OpenAPI");
        }

        return $node;
    }

    private static function escapePointerToken(string $token): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $token);
    }

    private static function document(): object
    {
        if (self::$document === null) {
            $parsed = Yaml::parseFile(base_path('openapi/v1/alittlebyte-mvp-api.yaml'));
            $document = Helper::toJSON($parsed);

            if (! is_object($document)) {
                Assert::fail('Contratto OpenAPI non leggibile: openapi/v1/alittlebyte-mvp-api.yaml');
            }

            self::$document = $document;
        }

        return self::$document;
    }

    private static function validator(): Validator
    {
        if (self::$validator === null) {
            $validator = new Validator;
            $validator->setMaxErrors(20);
            $validator->resolver()?->registerRaw(self::document(), self::SPEC_ID);

            self::$validator = $validator;
        }

        return self::$validator;
    }
}
