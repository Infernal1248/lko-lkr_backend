<?php

namespace Tests\Feature;

use App\Services\CardInput;
use App\Services\PdfRenderer;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GdPreviewTest extends TestCase
{
    public function test_both_templates_render_png_without_external_programs(): void
    {
        $renderer = app(PdfRenderer::class);
        $settings = ['offset_x' => 0, 'offset_y' => 0, 'font_size' => 12, 'fields' => []];
        foreach (['ppd-lkr', 'ppd-lko'] as $key) {
            $png = $renderer->preview($key, CardInput::demo(), $settings);
            $size = getimagesizefromstring($png);
            $this->assertSame(IMAGETYPE_PNG, $size[2]);
            $this->assertSame(914, $size[0]);
            $this->assertSame(556, $size[1]);
            $shifted = $renderer->preview($key, CardInput::demo(), array_replace($settings, ['offset_x' => 2]));
            $this->assertNotSame($png, $shifted);
            if (getenv('PREVIEW_QA_DIR')) {
                file_put_contents(getenv('PREVIEW_QA_DIR').'/'.$key.'.png', $png);
                file_put_contents(getenv('PREVIEW_QA_DIR').'/'.$key.'.pdf', $renderer->render($key, CardInput::demo(), $settings));
            }
        }
    }

    public function test_preview_and_pdf_reject_same_overflow(): void
    {
        $renderer = app(PdfRenderer::class);
        $data = CardInput::demo();
        $data['surname'] = str_repeat('Ш', 80);
        foreach (['preview', 'render'] as $method) {
            try {
                $renderer->$method('ppd-lkr', $data, ['offset_x' => 0, 'offset_y' => 0, 'font_size' => 12, 'fields' => []]);
                $this->fail('Overflow accepted');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('data.surname', $e->errors());
            }
        }
    }
}
