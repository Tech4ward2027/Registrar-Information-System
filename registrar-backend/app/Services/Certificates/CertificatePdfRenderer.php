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
    private const DEFAULT_HEADER_FONT_PT = 10.5;
    private const DEFAULT_LOGO_PX        = 70;
    private const DEFAULT_HEADER_LEFT   = 'certificate-default:puplogoimage.png';
    private const DEFAULT_HEADER_RIGHT  = 'certificate-default:Bagong_Pilipinas_logo.png';
    private const DEFAULT_FOOTER        = 'certificate-default:certificate_footer.png';
    private const DEFAULT_HEADER_LINES  = [
        'REPUBLIC OF THE PHILIPPINES',
        'Polytechnic University of the Philippines',
        'OFFICE OF THE VICE PRESIDENT FOR CAMPUSES',
        'TAGUIG CAMPUS',
    ];

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
        $options->set('chroot', [$temp, public_path()]);
        $options->set('fontDir', $temp);
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

        $margin = sprintf(
            '%smm %smm %smm %smm',
            $this->mm($m['top']),
            $this->mm($m['right']),
            $this->mm($m['bottom']),
            $this->mm($m['left'])
        );

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . $this->e($title) . '</title>'
            . '<style>'
            . "@page { margin: {$margin}; }"
            . "body { font-family: '{$font}', serif; font-size: {$pt}pt; line-height: 1.45; color: #000; margin: 0; }"
            . "@font-face { font-family: 'Lucida Fax'; font-style: normal; font-weight: 400; src: url('certificate-assets/fonts/Regular.ttf') format('truetype'); }"
            . "@font-face { font-family: 'Lucida Fax'; font-style: normal; font-weight: 700; src: url('certificate-assets/fonts/Demibold.ttf') format('truetype'); }"
            . "@font-face { font-family: 'Lucida Fax'; font-style: italic; font-weight: 400; src: url('certificate-assets/fonts/Italic.ttf') format('truetype'); }"
            . "@font-face { font-family: 'Lucida Fax'; font-style: italic; font-weight: 700; src: url('certificate-assets/fonts/DemiboldItalic.ttf') format('truetype'); }"
            . 'table { border-collapse: collapse; } td, th { vertical-align: top; }'
            . '.cert-header { width: 100%; margin-bottom: 14pt; text-align: center; border-bottom: 1.5pt solid #d1d5db; }'
            . '.cert-header td { vertical-align: middle; padding-bottom: 12pt; }'
            . ".cert-header-text { text-align: left; font-family: 'Lucida Fax', serif; text-transform: uppercase; }"
            . ".cert-header-text .main { font-weight: 700; line-height: 1.05; margin: 0; padding: 0; }"
            . ".cert-header-text .small { font-weight: 400; line-height: 1.05; margin: 0; padding: 0; }"
            . '.cert-footer { width: 100%; margin-top: 18pt; text-align: center; border-top: 1.5pt solid #d1d5db; }'
            . '.cert-footer td { vertical-align: top; padding-top: 8pt; }'
            . ".cert-footer-contact { text-align: left; font-family: Arial, Helvetica, sans-serif; font-size: 7.5pt; line-height: 1.15; color: #111; }"
            . ".cert-footer-tagline { margin-top: 2pt; font-size: 10pt; font-weight: 700; text-transform: uppercase; font-family: 'Lucida Fax', serif; color: #000; line-height: 1.1; }"
            . '.cert-footer-logos { text-align: right; white-space: nowrap; }'
            . '</style></head><body>'
            . $this->header($type)
            . '<div class="cert-body">' . $bodyHtml . '</div>'
            . $this->footer($type)
            . '</body></html>';
    }

    private function header(CertificationType $type): string
    {
        $size  = $this->logoPx($type->layout_header_logo_size);
        $left  = $this->logo($type->layout_header_left_url ?: self::DEFAULT_HEADER_LEFT, $size);
        $right = $this->logo($type->layout_header_right_url ?: self::DEFAULT_HEADER_RIGHT, $size);

        $lines = array_values(array_filter(
            array_map(fn ($line) => is_string($line) ? trim($line) : '', $type->layout_header_lines ?: self::DEFAULT_HEADER_LINES),
            fn (string $line) => $line !== ''
        ));

        $fontPt = $type->layout_header_font_size
            ? max(6, min(27, (int) $type->layout_header_font_size * 0.75))
            : self::DEFAULT_HEADER_FONT_PT;

        $text = '';
        foreach ($lines as $index => $line) {
            $isMain = ($index % 2) === 1;
            $class = $isMain ? 'main' : 'small';
            $text .= '<div class="' . $class . '" style="font-size: ' . rtrim(rtrim(number_format($fontPt, 2, '.', ''), '0'), '.') . 'pt;">'
                . $this->e($line)
                . '</div>';
        }

        $leftCell = $left !== ''
            ? '<td style="width: 1%; white-space: nowrap; text-align: left; vertical-align: middle; padding-right: 12pt;">' . $left . '</td>'
            : '';

        $rightCell = $right !== ''
            ? '<td style="width: 1%; white-space: nowrap; text-align: right; vertical-align: middle; padding-left: 12pt;">' . $right . '</td>'
            : '';

        return '<table class="cert-header"><tr>'
            . $leftCell
            . '<td class="cert-header-text" style="font-size: ' . $fontPt . 'pt; vertical-align: middle;">' . $text . '</td>'
            . $rightCell
            . '</tr></table>';
    }

    private function footer(CertificationType $type): string
    {
        $size = $this->logoPx($type->layout_footer_logo_size);
        $imgs = '';
        foreach ((array) ($type->layout_footer_urls ?? []) as $url) {
            $imgs .= $this->logo(is_string($url) ? $url : null, $size) . ' ';
        }
        if (trim($imgs) === '') {
            $imgs = $this->logo(self::DEFAULT_FOOTER, $size);
        }

        return '<table class="cert-footer"><tr>'
            . '<td class="cert-footer-contact">'
            . 'General Santos Avenue, Lower Bicutan, Taguig City, Philippines 1632<br>'
            . 'Direct Line: (02) 8837 5858 to 60 | Email: taguig@pup.edu.ph<br>'
            . 'Website: www.pup.edu.ph | Inquiries: https://bit.ly/PUPSINTA'
            . '<div class="cert-footer-tagline">THE COUNTRY\'S 1st POLYTECHNIC</div>'
            . '</td>'
            . '<td class="cert-footer-logos">' . trim($imgs) . '</td>'
            . '</tr></table>';
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
