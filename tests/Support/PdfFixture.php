<?php

declare(strict_types=1);

namespace Tests\Support;

final class PdfFixture
{
    public static function make(int $pages = 1, bool $encrypted = false): string
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
        ];
        $kids = [];
        $nextObject = 3;

        for ($page = 1; $page <= $pages; $page++) {
            $pageObject = $nextObject++;
            $contentObject = $nextObject++;
            $kids[] = $pageObject.' 0 R';
            $content = "0.8 w 24 24 564 744 re S\n";
            $objects[$pageObject] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << >> /Contents '.$contentObject.' 0 R >>';
            $objects[$contentObject] = '<< /Length '.strlen($content)." >>\nstream\n{$content}endstream";
        }

        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.$pages.' >>';

        if ($encrypted) {
            $encryptObject = $nextObject++;
            $objects[$encryptObject] = '<< /Filter /Standard /V 1 /R 2 /O <0000000000000000000000000000000000000000000000000000000000000000> /U <0000000000000000000000000000000000000000000000000000000000000000> /P -4 >>';
        }

        ksort($objects);
        $pdf = "%PDF-1.4\n";
        $offsets = [0 => 0];
        foreach ($objects as $number => $object) {
            $offsets[$number] = strlen($pdf);
            $pdf .= "{$number} 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $count = max(array_keys($objects)) + 1;
        $pdf .= "xref\n0 {$count}\n0000000000 65535 f \n";
        for ($number = 1; $number < $count; $number++) {
            $pdf .= sprintf('%010d 00000 n ', $offsets[$number])."\n";
        }
        $trailer = '<< /Size '.$count.' /Root 1 0 R';
        if ($encrypted) {
            $trailer .= ' /Encrypt '.($count - 1).' 0 R /ID [<00112233445566778899AABBCCDDEEFF><00112233445566778899AABBCCDDEEFF>]';
        }

        return $pdf."trailer\n{$trailer} >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
