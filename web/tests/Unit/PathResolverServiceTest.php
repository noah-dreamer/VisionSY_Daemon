<?php

namespace Tests\Unit;

use App\Services\Media\PathResolverService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PathResolverServiceTest extends TestCase
{
    private PathResolverService $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = app(PathResolverService::class);
    }

    /* ---------------- 路径规范化 ---------------- */

    public function test_normalize_with_dot_dot_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->resolver->normalizePath('foo/../bar');
    }

    public function test_normalize_with_null_byte_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->resolver->normalizePath("foo\0bar");
    }

    public function test_normalize_with_backslash_converted(): void
    {
        $result = $this->resolver->normalizePath('foo\\bar\\baz');

        $this->assertEquals('foo/bar/baz', $result);
    }

    public function test_normalize_removes_extra_slashes(): void
    {
        $result = $this->resolver->normalizePath('foo//bar///baz');

        $this->assertEquals('foo/bar/baz', $result);
    }

    public function test_normalize_removes_leading_slash(): void
    {
        $result = $this->resolver->normalizePath('/foo/bar');

        $this->assertEquals('foo/bar', $result);
    }

    /* ---------------- 相对路径验证 ---------------- */

    public function test_validate_relative_path_accepts_normal_path(): void
    {
        $this->expectNotToPerformAssertions();
        $this->resolver->validateRelativePath('DCIM/IMG_0001.CR3');
    }

    public function test_validate_relative_path_rejects_empty(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->resolver->validateRelativePath('');
    }

    public function test_validate_relative_path_rejects_dot(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->resolver->validateRelativePath('.');
    }

    public function test_validate_relative_path_rejects_dot_dot(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->resolver->validateRelativePath('../etc/passwd');
    }

    public function test_validate_relative_path_rejects_control_chars(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->resolver->validateRelativePath("test\x00hidden");
    }

    public function test_validate_relative_path_rejects_absolute_path(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->resolver->validateRelativePath('/absolute');
    }

    /* ---------------- 安全基础名 ---------------- */

    public function test_safe_basename_extracts_filename(): void
    {
        $result = $this->resolver->safeBasename('DCIM/IMG_0001.CR3');

        $this->assertEquals('IMG_0001.CR3', $result);
    }

    public function test_safe_basename_with_traversal_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->resolver->safeBasename('../etc/passwd');
    }

    /* ---------------- 路径边界验证 ---------------- */

    public function test_validate_within_root_accepts_subpath(): void
    {
        $tmp = sys_get_temp_dir().'/visionsy-root-'.bin2hex(random_bytes(4));
        mkdir($tmp, 0775, true);
        $sub = $tmp.'/subdir';
        mkdir($sub, 0775, true);
        try {
            $this->expectNotToPerformAssertions();
            $this->resolver->validateWithinRoot($sub, $tmp);
        } finally {
            $this->cleanTestDir($tmp);
        }
    }

    public function test_validate_within_root_rejects_escape(): void
    {
        $tmp = sys_get_temp_dir().'/visionsy-root-'.bin2hex(random_bytes(4));
        mkdir($tmp, 0775, true);
        try {
            $this->expectException(\RuntimeException::class);
            $this->resolver->validateWithinRoot('/etc/passwd', $tmp);
        } finally {
            $this->cleanTestDir($tmp);
        }
    }

    public function test_validate_within_root_rejects_root_not_exist(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->resolver->validateWithinRoot('/nonexistent/file.jpg', '/nonexistent-root');
    }

    public function test_validate_within_root_parent_dir_boundary_check_for_nonexistent_file(): void
    {
        $tmp = sys_get_temp_dir().'/visionsy-root-'.bin2hex(random_bytes(4));
        mkdir($tmp, 0775, true);
        mkdir($tmp.'/gallery', 0775, true);
        try {
            $this->expectNotToPerformAssertions();
            $this->resolver->validateWithinRoot($tmp.'/gallery/_visionsy/preview/thumb/file.jpg', $tmp);
        } finally {
            $this->cleanTestDir($tmp);
        }
    }

    public function test_validate_within_root_rejects_nonexistent_file_outside_root(): void
    {
        $tmp = sys_get_temp_dir().'/visionsy-root-'.bin2hex(random_bytes(4));
        mkdir($tmp, 0775, true);
        try {
            $this->expectException(\RuntimeException::class);
            $this->resolver->validateWithinRoot('/etc/nonexistent/file.jpg', $tmp);
        } finally {
            $this->cleanTestDir($tmp);
        }
    }

    public function test_validate_within_root_with_symlink_escape_uses_realpath(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('符号链接逃逸测试需要 Linux 的 /etc。');
        }

        $tmp = sys_get_temp_dir().'/visionsy-root-'.bin2hex(random_bytes(4));
        mkdir($tmp, 0775, true);
        $link = $tmp.'/link';
        symlink('/etc', $link);
        try {
            $this->expectException(\RuntimeException::class);
            $this->resolver->validateWithinRoot($link, $tmp);
        } finally {
            @unlink($link);
            $this->cleanTestDir($tmp);
        }
    }

    /* ---------------- 路径解析 ---------------- */

    public function test_resolve_path_within_directory(): void
    {
        $tmp = sys_get_temp_dir().'/visionsy-resolve-test-'.bin2hex(random_bytes(4));
        mkdir($tmp, 0775, true);
        try {
            $result = $this->resolver->resolvePath($tmp, 'some-subpath');
            $expectedPrefix = str_replace('\\', '/', $tmp);
            $this->assertStringStartsWith($expectedPrefix, $result);
        } finally {
            @rmdir($tmp);
        }
    }

    public function test_resolve_path_rejects_escape_via_parent_dir(): void
    {
        $tmp = sys_get_temp_dir().'/visionsy-root-'.bin2hex(random_bytes(4));
        mkdir($tmp, 0775, true);
        $outside = sys_get_temp_dir().'/visionsy-outside-'.bin2hex(random_bytes(4));
        mkdir($outside, 0775, true);
        try {
            $this->expectException(\RuntimeException::class);
            $this->resolver->resolvePath($tmp, '..', basename($outside));
        } finally {
            $this->cleanTestDir($tmp);
            @rmdir($outside);
        }
    }

    private function cleanTestDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $item) {
            $path = $dir.'/'.$item;
            is_dir($path) && ! is_link($path) ? $this->cleanTestDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /* ---------------- 旧模式路径（保留向后兼容） ---------------- */

    public function test_legacy_paths_use_storage_disk(): void
    {
        Storage::fake('local');

        $resource = new \App\Models\MediaResource([
            'storage_dir' => 'ab/cd/ulid123',
            'original_ext' => 'jpg',
        ]);
        $resource->exists = true;
        $resource->id = 1;

        $this->assertStringContainsString('media/', $this->resolver->resolveLegacyOriginalRelative($resource));
        $this->assertStringContainsString('ulid123', $this->resolver->resolveLegacyOriginalRelative($resource));
    }
}
