<?php

namespace App\Services\Certificates;

use App\Models\CertificateTemplate;
use App\Models\CertificationType;
use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;

/**
 * Turns an already-rendered certificate body into PDF bytes.
 *
 * Input is the TemplateRenderer's output (sanitised markup whose merge values
 * were inserted as escaped text), wrapped here in the page shell: paper size,
 * orientation, margins and the certificate type's letterhead (header lines
 * and header/footer logos).
 *
 * The engine is locked down: no remote fetching, no PHP, no JavaScript, and
 * local file access limited to its own scratch directory. Images only ever
 * arrive as data: URIs prepared by CertificateLayoutAssets.
 */
class CertificatePdfRenderer
{
    private const DEFAULT_HEADER_FONT_PT = 11;
    private const DEFAULT_LOGO_PX        = 70;

    public function __construct(private readonly CertificateLayoutAssets $assets) {}

    /**
     * @return string PDF bytes (never written to disk or object storage)
     *
     * @throws RuntimeException when the engine produces no output
     */
    public function render(string $bodyHtml, CertificateTemplate $template, CertificationType $type, string $title): string
    {
        $dompdf = new Dompdf($this->options());
        $dompdf->setPaper(strtolower($template->paper_size->value), $template->orientation->value);
        $dompdf->loadHtml($this->page($bodyHtml, $template, $type, $title), 'UTF-8');
        $dompdf->render();

        $output = $dompdf->output();
        if (!is_string($output) || !str_starts_with($output, '%PDF')) {
            throw new RuntimeException('The PDF engine returned no document.');
        }

        return $output;
    }

    private function options(): Options
    {
        $temp = (string) config('certificate_templates.pdf.temp_dir');
        if (!is_dir($temp) && !@mkdir($temp, 0775, true) && !is_dir($temp)) {
            throw new RuntimeException('The PDF scratch directory is not writable.');
        }

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isFontSubsettingEnabled', true);
        $options->set('chroot', [$temp]);
        $options->set('tempDir', $temp);
        $options->set('fontCache', $temp);
        $options->set('logOutputFile', '');
        $options->set('defaultFont', (string) config('certificate_templates.pdf.font', 'DejaVu Serif'));

        return $options;
    }

    private function page(string $bodyHtml, CertificateTemplate $template, CertificationType $type, string $title): string
    {
        $m    = $template->margins();
        $font = $this->e((string) config('certificate_templates.pdf.font', 'DejaVu Serif'));
        $pt   = (int) config('certificate_templates.pdf.font_size_pt', 12);

        $margin = sprintf('%smm %smm %smm %smm', $this->mm($m['top']), $this->mm($m['right']), $this->mm($m['bottom']), $this->mm($m['left']));

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . $this->e($title) . '</title>'
            . '<style>'
            . "@page { margin: {$margin}; }"
            . "body { font-family: '{$font}', serif; font-size: {$pt}pt; line-height: 1.45; color: #000; margin: 0; }"
            . 'table { border-collapse: collapse; } td, th { vertical-align: top; }'
            . '.cert-header { width: 100%; margin-bottom: 14pt; text-align: center; }'
            . '.cert-header td { vertical-align: middle; }'
            . '.cert-footer { width: 100%; margin-top: 18pt; text-align: center; }'
            . '</style></head><body>'
            . $this->header($type)
            . '<div class="cert-body">' . $bodyHtml . '</div>'
            . $this->footer($type)
            . '</body></html>';
    }

    private function header(CertificationType $type): string
    {
        $size  = $this->logoPx($type->layout_header_logo_size);
        $left  = $this->logo($type->layout_header_left_url, $size);
        $right = $this->logo($type->layout_header_right_url, $size);

        $lines = array_values(array_filter(
            array_map(fn ($line) => is_string($line) ? trim($line) : '', $type->layout_header_lines ?? []),
            fn (string $line) => $line !== ''
        ));

        if ($left === '' && $right === '' && $lines === []) {
            return '';
        }

        $fontPt = $type->layout_header_font_size
            ? max(6, min(36, (int) $type->layout_header_font_size))
            : self::DEFAULT_HEADER_FONT_PT;

        $text = implode('<br>', array_map(fn (string $line) => $this->e($line), $lines));

        return '<table class="cert-header"><tr>'
            . '<td style="width: 20%; text-align: left;">' . $left . '</td>'
            . '<td style="text-align: center; font-size: ' . $fontPt . 'pt;">' . $text . '</td>'
            . '<td style="width: 20%; text-align: right;">' . $right . '</td>'
            . '</tr></table>';
    }

    private function footer(CertificationType $type): string
    {
        $size = $this->logoPx($type->layout_footer_logo_size);
        $imgs = '';
        foreach ((array) ($type->layout_footer_urls ?? []) as $url) {
            $imgs .= $this->logo(is_string($url) ? $url : null, $size) . ' ';
        }

        return trim($imgs) === '' ? '' : '<div class="cert-footer">' . $imgs . '</div>';
    }

    private function logo(?string $storedUrl, int $heightPx): string
    {
        $uri = $this->assets->dataUri($storedUrl);

        return $uri === null ? '' : '<img src="' . $uri . '" style="height: ' . $heightPx . 'px;" alt="">';
    }

    private function logoPx(mixed $value): int
    {
        return is_numeric($value) && (int) $value > 0
            ? max(16, min(250, (int) $value))
            : self::DEFAULT_LOGO_PX;
    }

    private function mm(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
