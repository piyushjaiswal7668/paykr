<?php

declare(strict_types=1);

$path = $argv[1] ?? __DIR__.'/../storage/coverage.xml';
$xml = simplexml_load_file($path);
if ($xml === false) {
    fwrite(STDERR, "Cannot read coverage report.\n");
    exit(1);
}
$total = $covered = 0;
foreach ($xml->xpath('//file') ?: [] as $file) {
    if (! str_contains(str_replace('\\', '/', (string) $file['name']), '/app/Domain/')) {
        continue;
    }
    $total += (int) $file->metrics['statements'];
    $covered += (int) $file->metrics['coveredstatements'];
}
$percentage = $total > 0 ? 100 * $covered / $total : 0;
printf("Domain coverage: %.2f%% (required: 90%%)\n", $percentage);
exit($percentage >= 90 ? 0 : 1);
