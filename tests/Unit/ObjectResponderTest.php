<?php

declare(strict_types=1);

/**
 * Copyright 2026 codaipro — Lucky Yaduvanshi (https://luckyyaduvanshi.in)
 * Original source: https://github.com/Luckyyaduvanshiofficial/php-s3
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace PhpS3\Tests\Unit;

use PhpS3\S3\Handlers\ObjectResponder;
use PhpS3\Storage\LocalFilesystemStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ObjectResponderTest extends TestCase
{
    private string $root;
    private LocalFilesystemStorage $storage;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/php_s3_responder_' . bin2hex(random_bytes(6));
        @mkdir($this->root, 0750, true);
        $this->storage = new LocalFilesystemStorage($this->root);
    }

    protected function tearDown(): void
    {
        $this->recursiveRmdir($this->root);
    }

    /**
     * Object bytes share an origin with the admin panel; every object
     * response must carry CSP sandbox so an uploaded document cannot
     * execute scripts against /_admin.
     */
    public function testObjectGetIsSandboxed(): void
    {
        $response = $this->responder()->get(
            php_s3_test_request('GET', '/b/page.html'),
            $this->storedObject('text/html'),
        );

        self::assertSame(200, $response->status);
        self::assertSame('sandbox', $response->headers['Content-Security-Policy']);
    }

    public function testObjectHeadIsSandboxed(): void
    {
        $response = $this->responder()->get(
            php_s3_test_request('HEAD', '/b/page.html'),
            $this->storedObject('text/html'),
            true,
        );

        self::assertSame(200, $response->status);
        self::assertSame('sandbox', $response->headers['Content-Security-Policy']);
    }

    #[DataProvider('objectResponseVariants')]
    public function testStoredResponseMetadataIsReturned(
        string $method,
        bool $headOnly,
        array $requestHeaders,
        int $status,
        string $length,
    ): void {
        $object = $this->storedObject('text/csv') + [
            'content_encoding' => 'br',
            'content_disposition' => 'attachment; filename="log.csv"',
            'cache_control' => 'private, no-transform',
            'content_language' => 'en',
        ];
        $response = $this->responder()->get(
            php_s3_test_request($method, '/b/page.html', $requestHeaders),
            $object,
            $headOnly,
        );

        self::assertSame($status, $response->status);
        self::assertSame('text/csv', $response->headers['Content-Type']);
        self::assertSame('br', $response->headers['Content-Encoding']);
        self::assertSame('attachment; filename="log.csv"', $response->headers['Content-Disposition']);
        self::assertSame('private, no-transform', $response->headers['Cache-Control']);
        self::assertSame('en', $response->headers['Content-Language']);
        self::assertSame($length, $response->headers['Content-Length']);
    }

    public static function objectResponseVariants(): array
    {
        return [
            'GET' => ['GET', false, [], 200, '25'],
            'HEAD' => ['HEAD', true, [], 200, '25'],
            'ranged GET' => ['GET', false, ['range' => 'bytes=0-4'], 206, '5'],
        ];
    }

    public function testEmptyResponseMetadataIsNotReturned(): void
    {
        $object = $this->storedObject('text/csv') + [
            'content_encoding' => null,
            'content_disposition' => '',
            'cache_control' => null,
            'content_language' => '',
        ];
        $response = $this->responder()->get(
            php_s3_test_request('GET', '/b/page.html'),
            $object,
        );

        foreach (['Content-Encoding', 'Content-Disposition', 'Cache-Control', 'Content-Language'] as $header) {
            self::assertArrayNotHasKey($header, $response->headers);
        }
    }

    private function responder(): ObjectResponder
    {
        return new ObjectResponder($this->storage);
    }

    /** @return array<string, mixed> */
    private function storedObject(string $contentType): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, '<script>alert(1)</script>');
        rewind($stream);
        $staged = $this->storage->stage($stream, 25);
        fclose($stream);
        $path = $this->storage->commit('b', 'page.html', $staged);

        return [
            'size' => 25,
            'etag' => $staged->md5,
            'content_type' => $contentType,
            'updated_at' => gmdate('Y-m-d H:i:s'),
            'storage_path' => $path,
        ];
    }

    private function recursiveRmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->recursiveRmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
