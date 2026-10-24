<?php

namespace App\Services\FCH\Common\Borrower;

use Illuminate\Support\Facades\Log;

class IsoftpullParserService implements CreditProfileParserInterface
{
    /**
     * Known field labels that appear in iSoftPull trade account entries.
     * Used to identify boundaries and detect when we're inside a trade block.
     */
    private const TRADE_FIELD_LABELS = [
        'Date Reported', 'Date Of Closure', 'Account Status', 'Account Rating',
        'Account Number', 'Account Type', 'Portfolio Type', 'Remarks',
        'Payment History Start Date', 'Payment Frequency', 'Responsibility',
        'Date Of Opening', 'Terms', 'Last Payment Date', 'Balance',
        'High Credit', 'Credit Limit', 'Monthly Payment Amount',
        'Actual Payment Amount', 'Past Due', 'Months Reviewed',
        '30/60/90 Day Delinquencies', 'Max Delinquency Date',
    ];

    public function parse(string $text): array
    {
        $lines = $this->normalizeText($text);
        $linesCount = count($lines);
        $fullText = implode("\n", $lines);

        $personalInfo = $this->parsePersonalInfo($fullText);
        $metrics = $this->parseMetrics($fullText, $lines, $linesCount);
        $addresses = $this->parseAddresses($lines, $linesCount);
        $tradeAccounts = $this->parseTradeAccounts($lines, $linesCount, $fullText);
        $inquiries = $this->parseInquiries($lines, $linesCount);

        $delinquentCount = 0;
        foreach ($tradeAccounts as $trade) {
            if (($trade['past_due'] !== null && $trade['past_due'] > 0)
                || (isset($trade['status']) && strtoupper($trade['status']) === 'DELINQUENT')) {
                $delinquentCount++;
            }
        }
        $metrics['delinquent_accounts'] = $delinquentCount;
        $metrics['summary'] = $this->generateSummary($personalInfo, $metrics, $tradeAccounts, $inquiries);

        return [
            'personal_info' => $personalInfo,
            'metrics' => $metrics,
            'addresses' => $addresses,
            'trade_accounts' => $tradeAccounts,
            'inquiries' => $inquiries,
        ];
    }

    private function normalizeText(string $text): array
    {
        $text = str_replace(
            ["\xc2\xa0", "\xe2\x80\x93", "\xe2\x80\x94", "\xe2\x80\x98", "\xe2\x80\x99"],
            [' ', '-', '--', "'", "'"],
            $text
        );
        $rawLines = explode("\n", $text);
        $clean = [];
        foreach ($rawLines as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                $clean[] = $trimmed;
            }
        }
        return $clean;
    }

    private function parsePersonalInfo(string $text): array
    {
        $info = ['name' => null, 'dob' => null, 'ssn' => null, 'email' => null, 'phone' => null];

        if (preg_match('/Name:\s*(.+?)(?:\s*Date\s+Of\s+Birth:|\s*SSN:|\s*Email:|\s*Phone:|\n)/is', $text, $m)) {
            $info['name'] = trim($m[1]);
        } elseif (preg_match('/Name:\s*(.+)/i', $text, $m)) {
            $info['name'] = trim($m[1]);
        }
        if (preg_match('/Date\s+Of\s+Birth:\s*([\d\/]+)/i', $text, $m)) {
            $info['dob'] = $m[1];
        }
        if (preg_match('/SSN:\s*([\d\-\*Xx]+)/i', $text, $m)) {
            $info['ssn'] = $m[1];
        }
        if (preg_match('/Email:\s*([^\s\n,;]+)/i', $text, $m)) {
            $info['email'] = trim($m[1]);
        }
        if (preg_match('/Phone:\s*([\d\-\(\)\s\.]+)/i', $text, $m)) {
            $info['phone'] = trim($m[1]);
        }
        return $info;
    }

    private function parseMetrics(string $fullText, array $lines, int $linesCount): array
    {
        $metrics = [
            'fico_score' => null,
            'paydex_score' => null,
            'experian_intelliscore' => null,
            'equifax_score' => null,
            'active_tradelines' => null,
            'total_credit_limit' => null,
            'credit_utilization' => null,
            'recent_inquiries' => null,
            'delinquent_accounts' => null,
            'public_records' => null,
            'collections' => null,
            'delinquencies_30_60_90' => null,
            'summary' => '',
            'all_accounts_analysis' => [
                'total_accounts' => null, 'open_accounts' => null, 'closed_accounts' => null,
                'newest_open_date' => null, 'oldest_open_date' => null,
            ],
            'open_accounts_analysis' => [
                'monthly_payments' => null, 'balance' => null, 'credit_limit' => null,
                'available_credit' => null, 'utilization_ratio' => null,
            ],
        ];

        if (preg_match('/Score\s+Model:\s*FICO\s*\d*\s*Score:\s*(\d+)/is', $fullText, $m)) {
            $metrics['fico_score'] = (int)$m[1];
        } elseif (preg_match('/FICO\s*\d*\s*Score:\s*(\d+)/i', $fullText, $m)) {
            $metrics['fico_score'] = (int)$m[1];
        } elseif (preg_match('/FICO.*?(\d{3})\s*\n/is', $fullText, $m)) {
            $metrics['fico_score'] = (int)$m[1];
        }
        if (preg_match('/Paydex\s*Score:\s*(\d+)/i', $fullText, $m)) {
            $metrics['paydex_score'] = (int)$m[1];
        }
        if (preg_match('/Intelliscore:\s*(\d+)/i', $fullText, $m)) {
            $metrics['experian_intelliscore'] = (int)$m[1];
        }
        if (preg_match('/Equifax\s+Score:\s*(\d+)/i', $fullText, $m)) {
            $metrics['equifax_score'] = (int)$m[1];
        }
        if (preg_match('/Inquiries:\s*(\d+)/i', $fullText, $m)) {
            $metrics['recent_inquiries'] = (int)$m[1];
        }
        if (preg_match('/Collections:\s*(\d+)/i', $fullText, $m)) {
            $metrics['collections'] = (int)$m[1];
        }
        if (preg_match('/Public\s+Records:\s*(\d+)/i', $fullText, $m)) {
            $metrics['public_records'] = (int)$m[1];
        }
        
        // Use horizontal space matching to avoid crossing newlines
        if (preg_match('/30\s*\/\s*60\s*\/\s*90[^\S\r\n]*[:\t ]*([^\r\n]+)/i', $fullText, $m)) {
            $metrics['delinquencies_30_60_90'] = trim($m[1]);
        }

        $metrics = $this->parseAccountAnalysisGrids($lines, $linesCount, $metrics);
        return $metrics;
    }

    private function parseAccountAnalysisGrids(array $lines, int $linesCount, array $metrics): array
    {
        $sectionEndMarkers = [
            'TRADE ACCOUNTS', 'CREDIT INQUIRIES', 'INQUIRIES',
            'ADDRESS HISTORY', 'PUBLIC RECORDS', 'COLLECTIONS',
            'PERSONAL INFORMATION', 'CREDIT SCORE',
            'OPEN ACCOUNTS ANALYSIS', 'ALL ACCOUNTS ANALYSIS',
        ];

        for ($i = 0; $i < $linesCount; $i++) {
            $lineUpper = strtoupper(trim($lines[$i]));

            if ($lineUpper === 'ALL ACCOUNTS ANALYSIS' || $lineUpper === 'ALL ACCOUNTS') {
                $sectionEnd = $this->findSectionEnd($lines, $linesCount, $i + 1, $sectionEndMarkers);
                $sectionLines = array_slice($lines, $i + 1, $sectionEnd - $i - 1);
                $metrics = $this->parseGridSection($sectionLines, 'all_accounts', $metrics);
            }

            if ($lineUpper === 'OPEN ACCOUNTS ANALYSIS' || $lineUpper === 'OPEN ACCOUNTS') {
                $sectionEnd = $this->findSectionEnd($lines, $linesCount, $i + 1, $sectionEndMarkers);
                $sectionLines = array_slice($lines, $i + 1, $sectionEnd - $i - 1);
                $metrics = $this->parseGridSection($sectionLines, 'open_accounts', $metrics);
            }
        }
        return $metrics;
    }

    private function parseGridSection(array $sectionLines, string $sectionType, array $metrics): array
    {
        $allRows = [
            'accounts' => ['key' => 'accounts_grid', 'type' => 'number'],
            'open accounts' => ['key' => 'open_accounts_grid', 'type' => 'number'],
            'closed accounts' => ['key' => 'closed_accounts_grid', 'type' => 'number'],
            'newest open date' => ['key' => 'newest_open_date_grid', 'type' => 'date'],
            'oldest open date' => ['key' => 'oldest_open_date_grid', 'type' => 'date'],
            'monthly payments' => ['key' => 'monthly_payments_grid', 'type' => 'number'],
            'balance' => ['key' => 'balance_grid', 'type' => 'number'],
            'credit limit' => ['key' => 'credit_limit_grid', 'type' => 'number'],
            'available credit' => ['key' => 'available_credit_grid', 'type' => 'number'],
            'utilization ratio' => ['key' => 'utilization_ratio_grid', 'type' => 'percent'],
        ];

        // Sort by key length descending to check longest labels first
        uksort($allRows, function($a, $b) {
            return strlen($b) <=> strlen($a);
        });

        $count = count($sectionLines);
        $i = 0;

        while ($i < $count) {
            $currentLine = trim($sectionLines[$i]);
            $currentUpper = strtoupper($currentLine);

            $matchedLabel = null;
            $skipLines = 0;

            foreach ($allRows as $label => $config) {
                $labelUpper = strtoupper($label);

                // Try merging L = 1 to 3 lines
                for ($L = 1; $L <= 3; $L++) {
                    if ($i + $L <= $count) {
                        $slice = array_slice($sectionLines, $i, $L);
                        $combined = implode(' ', $slice);
                        $combinedUpper = strtoupper(trim($combined));

                        // Check if it starts with the label
                        if ($combinedUpper === $labelUpper || strpos($combinedUpper, $labelUpper) === 0) {
                            $matchedLabel = $label;
                            $skipLines = $L - 1;
                            break 2;
                        }
                    }
                }
            }

            if ($matchedLabel !== null) {
                $rowData = null;
                $type = $allRows[$matchedLabel]['type'];

                // If label was matched on current line alone (L=1), check for data on same line
                if ($skipLines === 0) {
                    $afterLabel = preg_replace('/^' . preg_quote($matchedLabel, '/') . '/i', '', $currentLine);
                    $afterLabel = trim($afterLabel);
                    if (!empty($afterLabel) && $afterLabel !== $currentLine) {
                        $rowData = $afterLabel;
                    }
                }

                if ($rowData === null) {
                    $searchStart = $i + 1 + $skipLines;
                    for ($k = $searchStart; $k < min($searchStart + 4, $count); $k++) {
                        $candidate = trim($sectionLines[$k]);

                        if ($this->isGridRowLabel($candidate)) {
                            break;
                        }

                        if (!empty($candidate)) {
                            $rowData = $candidate;
                            break;
                        }
                    }
                }

                if ($rowData !== null) {
                    $grid = $this->parseRowToGrid($rowData, $type);
                    $gridKey = $allRows[$matchedLabel]['key'];

                    if ($sectionType === 'all_accounts') {
                        $metrics['all_accounts_analysis'][$gridKey] = $grid;

                        switch ($gridKey) {
                            case 'accounts_grid':
                                $metrics['all_accounts_analysis']['total_accounts'] = (int)($grid['all'] ?? 0);
                                break;
                            case 'open_accounts_grid':
                                $metrics['all_accounts_analysis']['open_accounts'] = (int)($grid['all'] ?? 0);
                                $metrics['active_tradelines'] = (int)($grid['all'] ?? 0);
                                break;
                            case 'closed_accounts_grid':
                                $metrics['all_accounts_analysis']['closed_accounts'] = (int)($grid['all'] ?? 0);
                                break;
                            case 'newest_open_date_grid':
                                $metrics['all_accounts_analysis']['newest_open_date'] = $grid['all'] ?? null;
                                break;
                            case 'oldest_open_date_grid':
                                $metrics['all_accounts_analysis']['oldest_open_date'] = $grid['all'] ?? null;
                                break;
                        }
                    } else {
                        $metrics['open_accounts_analysis'][$gridKey] = $grid;

                        switch ($gridKey) {
                            case 'monthly_payments_grid':
                                $metrics['open_accounts_analysis']['monthly_payments'] = $grid['all'] ?? 0;
                                break;
                            case 'balance_grid':
                                $metrics['open_accounts_analysis']['balance'] = $grid['all'] ?? 0;
                                break;
                            case 'credit_limit_grid':
                                $metrics['open_accounts_analysis']['credit_limit'] = $grid['all'] ?? 0;
                                $metrics['total_credit_limit'] = $grid['all'] ?? 0;
                                break;
                            case 'available_credit_grid':
                                $metrics['open_accounts_analysis']['available_credit'] = $grid['all'] ?? 0;
                                break;
                            case 'utilization_ratio_grid':
                                $metrics['open_accounts_analysis']['utilization_ratio'] = $grid['all'] ?? 0;
                                $metrics['credit_utilization'] = $grid['all'] ?? 0;
                                break;
                        }
                    }
                }

                $i += 1 + $skipLines;
            } else {
                $i++;
            }
        }

        return $metrics;
    }

    private function isGridRowLabel(string $line): bool
    {
        $upper = strtoupper(trim($line));
        $labels = [
            'ACCOUNTS', 'OPEN ACCOUNTS', 'CLOSED ACCOUNTS',
            'NEWEST OPEN DATE', 'OLDEST OPEN DATE',
            'MONTHLY PAYMENTS', 'BALANCE', 'CREDIT LIMIT',
            'AVAILABLE CREDIT', 'UTILIZATION RATIO',
            'OPEN', 'CLOSED', 'NEWEST', 'OLDEST', 'MONTHLY',
            'CREDIT', 'AVAILABLE', 'UTILIZATION',
        ];
        return in_array($upper, $labels);
    }

    private function findSectionEnd(array $lines, int $linesCount, int $start, array $markers): int
    {
        for ($i = $start; $i < $linesCount; $i++) {
            $upper = strtoupper(trim($lines[$i]));
            foreach ($markers as $marker) {
                if ($upper === $marker || strpos($upper, $marker) === 0) {
                    return $i;
                }
            }
        }
        return $linesCount;
    }

    private function parseAddresses(array $lines, int $linesCount): array
    {
        $addresses = [];
        $inAddressSection = false;
        $currentAddr = null;
        $endMarkers = ['TRADE ACCOUNTS', 'CREDIT SCORE', 'ALL ACCOUNTS ANALYSIS', 'OPEN ACCOUNTS ANALYSIS', 'INQUIRIES', 'CREDIT INQUIRIES'];

        for ($i = 0; $i < $linesCount; $i++) {
            $lineUpper = strtoupper(trim($lines[$i]));

            if (!$inAddressSection && ($lineUpper === 'ADDRESS HISTORY' || strpos($lineUpper, 'ADDRESS HISTORY') === 0)) {
                $inAddressSection = true;
                continue;
            }

            if ($inAddressSection) {
                $isEnd = false;
                foreach ($endMarkers as $marker) {
                    if (strpos($lineUpper, $marker) === 0) {
                        $isEnd = true;
                        break;
                    }
                }
                if ($isEnd) {
                    if ($currentAddr !== null) {
                        $addresses[] = $currentAddr;
                        $currentAddr = null; // Set to null to prevent duplicate push after loop
                    }
                    break;
                }

                if ($lineUpper === 'CURRENT' || $lineUpper === 'PREVIOUS') {
                    if ($currentAddr !== null) {
                        $addresses[] = $currentAddr;
                    }
                    $currentAddr = ['type' => strtoupper(trim($lines[$i])), 'street' => '', 'city' => '', 'state' => '', 'zip' => '', 'date_reported' => null];
                    continue;
                }

                if ($currentAddr !== null) {
                    $lineVal = $lines[$i];
                    if (preg_match('/Street:\s*(.+)/i', $lineVal, $m)) {
                        $currentAddr['street'] = trim($m[1]);
                    } elseif (preg_match('/City:\s*(.+)/i', $lineVal, $m)) {
                        $cityPart = $m[1];
                        if (preg_match('/^(.*?)[,\s]+State:\s*(.*?)(?:[,\s]+Zip:\s*(.*))?$/i', $cityPart, $sm)) {
                            $currentAddr['city'] = trim($sm[1]);
                            $currentAddr['state'] = trim($sm[2] ?? '');
                            $currentAddr['zip'] = trim($sm[3] ?? '');
                        } else {
                            $currentAddr['city'] = trim(str_ireplace('City:', '', $cityPart));
                        }
                    } elseif (preg_match('/State:\s*(.*?)(?:[,\s]+Zip:\s*(.*))?$/i', $lineVal, $m)) {
                        $currentAddr['state'] = trim($m[1]);
                        $currentAddr['zip'] = trim($m[2] ?? '');
                    } elseif (preg_match('/Zip:\s*(.+)/i', $lineVal, $m)) {
                        $currentAddr['zip'] = trim($m[1]);
                    } elseif (preg_match('/Date\s+Reported:\s*(.+)/i', $lineVal, $m)) {
                        $currentAddr['date_reported'] = trim($m[1]);
                    }
                }
            }
        }
        if ($currentAddr !== null) {
            $addresses[] = $currentAddr;
        }
        return $addresses;
    }

    private function parseTradeAccounts(array $lines, int $linesCount, string $fullText): array
    {
        // Strategy 1: Line-by-line Company: markers
        $result = $this->parseTradeAccountsByCompanyMarker($lines, $linesCount);
        if (!empty($result)) {
            return $result;
        }

        // Strategy 2: Regex block extraction from full text
        $result = $this->parseTradeAccountsFallback($fullText);
        if (!empty($result)) {
            return $result;
        }

        // Strategy 3: Deep scan - find any field patterns in the trade section
        $result = $this->parseTradeAccountsDeepScan($lines, $linesCount, $fullText);
        if (!empty($result)) {
            return $result;
        }

        Log::warning('IsoftpullParser: All trade parsing strategies failed');
        return [];
    }

    private function parseTradeAccountsByCompanyMarker(array $lines, int $linesCount): array
    {
        $tradeSectionStart = -1;
        $tradeSectionEnd = $linesCount;
        $sectionStartMarkers = ['TRADE ACCOUNTS', 'TRADE'];
        $sectionEndMarkers = ['INQUIRIES', 'CREDIT INQUIRIES', 'HARD INQUIRIES', 'PUBLIC RECORDS', 'COLLECTIONS'];

        for ($i = 0; $i < $linesCount; $i++) {
            $lineUpper = strtoupper(trim($lines[$i]));
            foreach ($sectionStartMarkers as $marker) {
                if ($lineUpper === $marker || strpos($lineUpper, $marker) === 0) {
                    if ($tradeSectionStart === -1) $tradeSectionStart = $i;
                }
            }
        }

        if ($tradeSectionStart === -1) return [];

        for ($i = $tradeSectionStart + 1; $i < $linesCount; $i++) {
            $lineUpper = strtoupper(trim($lines[$i]));
            foreach ($sectionEndMarkers as $marker) {
                if ($lineUpper === $marker || strpos($lineUpper, $marker) === 0) {
                    $tradeSectionEnd = $i;
                    break 2;
                }
            }
        }

        $tradeLines = array_slice($lines, $tradeSectionStart + 1, $tradeSectionEnd - $tradeSectionStart - 1);

        $tradeBlocks = [];
        $currentBlock = [];

        foreach ($tradeLines as $line) {
            if (preg_match('/Company:\s*(.+)/i', $line, $m)) {
                if (!empty($currentBlock)) {
                    $tradeBlocks[] = implode("\n", $currentBlock);
                }
                $currentBlock = ['Company:' . trim($m[1])];
            } elseif (preg_match('/\bCompany:/i', $line)) {
                if (!empty($currentBlock)) {
                    $tradeBlocks[] = implode("\n", $currentBlock);
                }
                if (preg_match('/Company:\s*(.+)/i', $line, $m)) {
                    $currentBlock = ['Company:' . trim($m[1])];
                } else {
                    $currentBlock = [$line];
                }
            } else {
                $currentBlock[] = $line;
            }
        }
        if (!empty($currentBlock)) {
            $tradeBlocks[] = implode("\n", $currentBlock);
        }

        if (empty($tradeBlocks)) return [];

        $tradeAccounts = [];
        foreach ($tradeBlocks as $blockText) {
            $trade = $this->parseSingleTradeAccount($blockText);
            if ($trade !== null && !empty($trade['company'])) {
                if (empty($trade['account_number']) && empty($trade['date_reported'])) {
                    continue;
                }
                $tradeAccounts[] = $trade;
            }
        }
        return $tradeAccounts;
    }

    private function parseTradeAccountsFallback(string $fullText): array
    {
        $tradeBlock = '';
        if (preg_match('/Trade\s+Accounts(.*?)(?:Inquiries|Credit\s+Inquiries|Hard\s+Inquiries|Public\s+Records|Collections)/is', $fullText, $matches)) {
            $tradeBlock = $matches[1];
        }
        if (empty($tradeBlock)) return [];

        $companiesRaw = preg_split('/\bCompany:\s*/i', $tradeBlock);
        array_shift($companiesRaw);

        $tradeAccounts = [];
        foreach ($companiesRaw as $compRaw) {
            $compText = trim($compRaw);
            if (empty($compText)) continue;
            $blockText = 'Company:' . $compText;
            $trade = $this->parseSingleTradeAccount($blockText);
            if ($trade !== null && !empty($trade['company'])) {
                if (empty($trade['account_number']) && empty($trade['date_reported'])) {
                    continue;
                }
                $tradeAccounts[] = $trade;
            }
        }
        return $tradeAccounts;
    }

    private function parseTradeAccountsDeepScan(array $lines, int $linesCount, string $fullText): array
    {
        $tradeSectionStart = -1;
        $tradeSectionEnd = $linesCount;
        $sectionStartMarkers = ['TRADE ACCOUNTS', 'TRADE'];
        $sectionEndMarkers = ['INQUIRIES', 'CREDIT INQUIRIES', 'HARD INQUIRIES', 'PUBLIC RECORDS', 'COLLECTIONS'];

        for ($i = 0; $i < $linesCount; $i++) {
            $lineUpper = strtoupper(trim($lines[$i]));
            foreach ($sectionStartMarkers as $marker) {
                if ($lineUpper === $marker || strpos($lineUpper, $marker) === 0) {
                    if ($tradeSectionStart === -1) $tradeSectionStart = $i;
                }
            }
        }
        if ($tradeSectionStart === -1) return [];

        for ($i = $tradeSectionStart + 1; $i < $linesCount; $i++) {
            $lineUpper = strtoupper(trim($lines[$i]));
            foreach ($sectionEndMarkers as $marker) {
                if ($lineUpper === $marker || strpos($lineUpper, $marker) === 0) {
                    $tradeSectionEnd = $i;
                    break 2;
                }
            }
        }

        $tradeSectionText = implode("\n", array_slice($lines, $tradeSectionStart + 1, $tradeSectionEnd - $tradeSectionStart - 1));

        $blocks = preg_split('/(?=Company:\s)/i', $tradeSectionText);
        array_shift($blocks);

        $tradeAccounts = [];
        foreach ($blocks as $block) {
            $block = trim($block);
            if (empty($block)) continue;
            $trade = $this->parseSingleTradeAccount($block);
            if ($trade !== null && !empty($trade['company'])) {
                if (empty($trade['account_number']) && empty($trade['date_reported'])) {
                    continue;
                }
                $tradeAccounts[] = $trade;
            }
        }

        return $tradeAccounts;
    }

    private function isLineALabel(string $line): bool
    {
        $line = trim($line);
        if (preg_match('/^([^:]+):/', $line, $m)) {
            $labelCandidate = trim(strtoupper($m[1]));
            $knownLabels = [
                'COMPANY', 'DATE REPORTED', 'DATE OF CLOSURE', 'ACCOUNT STATUS',
                'ACCOUNT RATING', 'ACCOUNT NUMBER', 'ACCOUNT TYPE', 'PORTFOLIO TYPE',
                'REMARKS', 'PAYMENT HISTORY START DATE', 'PAYMENT FREQUENCY',
                'RESPONSIBILITY', 'DATE OF OPENING', 'TERMS', 'LAST PAYMENT DATE',
                'BALANCE', 'HIGH CREDIT', 'CREDIT LIMIT', 'MONTHLY PAYMENT AMOUNT',
                'ACTUAL PAYMENT AMOUNT', 'PAST DUE', 'MONTHS REVIEWED',
                '30/60/90 DAY DELINQUENCIES', 'MAX DELINQUENCY DATE'
            ];
            return in_array($labelCandidate, $knownLabels);
        }
        return false;
    }

    private function extractSingleLineValue(string $labelText, string $blockText): ?string
    {
        $pattern = '/' . preg_quote($labelText, '/') . '\s*(.*)/is';
        if (preg_match($pattern, $blockText, $m)) {
            $after = $m[1];
            $lines = explode("\n", $after);
            $firstLine = isset($lines[0]) ? trim($lines[0]) : '';
            if ($firstLine !== '') {
                if (!$this->isLineALabel($firstLine)) {
                    return $firstLine;
                }
                return null;
            }
            if (isset($lines[1])) {
                $secondLine = trim($lines[1]);
                if ($secondLine !== '' && !$this->isLineALabel($secondLine)) {
                    return $secondLine;
                }
            }
        }
        return null;
    }

    private function parseSingleTradeAccount(string $text): ?array
    {
        $text = trim($text);
        if (empty($text)) return null;

        $lines = explode("\n", $text);
        $cleanLines = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (preg_match('/^(?:\s*(?:[0-9E\*]|UR|\-|OK|ND|E)\s*)+$/i', $trimmed)) {
                continue;
            }
            $cleanLines[] = $line;
        }
        $text = implode("\n", $cleanLines);

        $trade = [
            'company' => '', 'date_reported' => null, 'status' => 'OPEN',
            'rating' => null, 'account_number' => null, 'account_type' => null,
            'portfolio_type' => null, 'remarks' => null,
            'payment_history_start_date' => null, 'payment_frequency' => null,
            'responsibility' => null, 'opened_date' => null, 'terms' => null,
            'last_payment_date' => null, 'balance' => null, 'high_credit' => null,
            'limit' => null, 'monthly_payment' => null, 'actual_payment' => null,
            'past_due' => null, 'months_reviewed' => null,
            'delinquencies' => null, 'max_delinquency_date' => null,
        ];

        $boundaries = [
            'Company', 'Date\s+Reported', 'Date\s+Of\s+Closure', 'Account\s+Status',
            'Account\s+Rating', 'Account\s+Number', 'Account\s+Type', 'Portfolio\s+Type',
            'Remarks', 'Payment\s+History\s+Start\s+Date', 'Payment\s+Frequency',
            'Responsibility', 'Date\s+Of\s+Opening', 'Terms', 'Last\s+Payment\s+Date',
            'Balance', 'High\s+Credit', 'Credit\s+Limit', 'Monthly\s+Payment\s+Amount',
            'Actual\s+Payment\s+Amount', 'Past\s+Due', 'Months\s+Reviewed',
            '30\/60\/90\s+Day\s+Delinquencies', 'Max\s+Delinquency\s+Date'
        ];
        
        $la = '(?=\s*(?:' . implode('|', $boundaries) . ')\s*:|$)';

        if (preg_match('/Company:\s*(.*?)' . $la . '/is', $text, $m)) {
            $trade['company'] = trim(preg_replace('/\s+/', ' ', $m[1]));
        }
        if (empty($trade['company'])) {
            $firstLine = explode("\n", $text)[0];
            $trade['company'] = trim(preg_replace('/\s+/', ' ', $firstLine));
        }

        $trade['date_reported'] = $this->extractSingleLineValue('Date Reported:', $text);
        
        if (preg_match('/Account\s+Status:\s*(.*?)' . $la . '/is', $text, $m)) {
            $trade['status'] = strtoupper(trim(preg_replace('/\s+/', ' ', $m[1])));
        }
        
        if (preg_match('/Account\s+Rating:\s*(.*?)' . $la . '/is', $text, $m)) {
            $trade['rating'] = trim(preg_replace('/\s+/', ' ', $m[1]));
        }
        
        $trade['account_number'] = $this->extractSingleLineValue('Account Number:', $text);
        
        if (preg_match('/Account\s+Type:\s*(.*?)' . $la . '/is', $text, $m)) {
            $trade['account_type'] = trim(preg_replace('/\s+/', ' ', $m[1]));
        }
        
        if (preg_match('/Portfolio\s+Type:\s*(.*?)' . $la . '/is', $text, $m)) {
            $trade['portfolio_type'] = trim(preg_replace('/\s+/', ' ', $m[1]));
        }

        if (preg_match_all('/Remarks:\s*(.*?)' . $la . '/is', $text, $matches)) {
            $longestRemarks = '';
            foreach ($matches[1] as $r) {
                $cleaned = trim(preg_replace('/\s+/', ' ', $r));
                if (strlen($cleaned) > strlen($longestRemarks)) {
                    $longestRemarks = $cleaned;
                }
            }
            $trade['remarks'] = $longestRemarks;
        }

        $trade['payment_history_start_date'] = $this->extractSingleLineValue('Payment History Start Date:', $text);
        
        if (preg_match('/Payment\s+Frequency:\s*(.*?)' . $la . '/is', $text, $m)) {
            $trade['payment_frequency'] = trim(preg_replace('/\s+/', ' ', $m[1]));
        }
        
        if (preg_match('/Responsibility:\s*(.*?)' . $la . '/is', $text, $m)) {
            $trade['responsibility'] = trim(preg_replace('/\s+/', ' ', $m[1]));
        }
        
        $trade['opened_date'] = $this->extractSingleLineValue('Date Of Opening:', $text);
        
        if (preg_match('/Terms:\s*(.*?)' . $la . '/is', $text, $m)) {
            $trade['terms'] = trim(preg_replace('/\s+/', ' ', $m[1]));
        }
        
        $trade['last_payment_date'] = $this->extractSingleLineValue('Last Payment Date:', $text);

        $trade['balance'] = $this->parseNumber($this->extractSingleLineValue('Balance:', $text));
        $trade['high_credit'] = $this->parseNumber($this->extractSingleLineValue('High Credit:', $text));
        $trade['limit'] = $this->parseNumber($this->extractSingleLineValue('Credit Limit:', $text));
        $trade['monthly_payment'] = $this->parseNumber($this->extractSingleLineValue('Monthly Payment Amount:', $text));
        $trade['actual_payment'] = $this->parseNumber($this->extractSingleLineValue('Actual Payment Amount:', $text));
        $trade['past_due'] = $this->parseNumber($this->extractSingleLineValue('Past Due:', $text));

        $mr = $this->extractSingleLineValue('Months Reviewed:', $text);
        $trade['months_reviewed'] = $mr !== null ? (int)$mr : null;
        
        $trade['delinquencies'] = $this->extractSingleLineValue('30/60/90 Day Delinquencies:', $text);
        $trade['max_delinquency_date'] = $this->extractSingleLineValue('Max Delinquency Date:', $text);

        if (empty($trade['company'])) return null;
        return $trade;
    }

    private function parseInquiries(array $lines, int $linesCount): array
    {
        $inquiries = [];
        $inquiriesStart = -1;
        $inquiriesEnd = $linesCount;

        for ($i = 0; $i < $linesCount; $i++) {
            $lineUpper = strtoupper(trim($lines[$i]));
            if ($lineUpper === 'INQUIRIES' || $lineUpper === 'CREDIT INQUIRIES' || $lineUpper === 'HARD INQUIRIES' || strpos($lineUpper, 'INQUIRIES') === 0) {
                // Skip summary metric line "Inquiries: 2" or "Inquiries    2"
                if (preg_match('/(:\s*\d+|\b\d+)$/i', $lineUpper)) {
                    continue;
                }
                $inquiriesStart = $i;
                break;
            }
        }
        if ($inquiriesStart === -1) return [];

        $endMarkers = ['ADDRESS HISTORY', 'TRADE ACCOUNTS', 'ALL ACCOUNTS', 'OPEN ACCOUNTS', 'PUBLIC RECORDS', 'COLLECTIONS'];
        for ($i = $inquiriesStart + 1; $i < $linesCount; $i++) {
            $lineUpper = strtoupper(trim($lines[$i]));
            foreach ($endMarkers as $marker) {
                if (strpos($lineUpper, $marker) === 0) {
                    $inquiriesEnd = $i;
                    break 2;
                }
            }
        }

        $inquirySectionLines = array_slice($lines, $inquiriesStart + 1, $inquiriesEnd - $inquiriesStart - 1);
        $inquiryBlock = implode("\n", $inquirySectionLines);

        $inquiriesRaw = preg_split('/\bDate:\s*/i', $inquiryBlock);
        array_shift($inquiriesRaw);

        foreach ($inquiriesRaw as $inqRaw) {
            $inqRaw = trim($inqRaw);
            if (empty($inqRaw)) continue;

            $date = '';
            $company = '';
            $industry = '';

            if (preg_match('/^([\d\/]+)/', $inqRaw, $m)) $date = $m[1];
            if (preg_match('/Company:\s*(.*?)(?=Industry:|$)/is', $inqRaw, $m)) $company = trim(preg_replace('/\s+/', ' ', $m[1]));
            if (preg_match('/Industry:\s*(.*?)$/ims', $inqRaw, $m)) $industry = trim(preg_replace('/\s+/', ' ', $m[1]));

            $company = preg_replace('/success@isoftpull\.com.*$/is', '', $company);
            $company = trim(preg_replace('/\d{3}[\s\-]?\d{3}[\s\-]?\d{4}.*$/is', '', $company));
            $industry = preg_replace('/success@isoftpull\.com.*$/is', '', $industry);
            $industry = trim(preg_replace('/2270\s+Camino.*$/is', '', $industry));
            $industry = trim(preg_replace('/www\.isoftpull\.com.*$/is', '', $industry));

            if (empty($date) && empty($company)) continue;

            $inquiries[] = ['date' => $date, 'company' => $company, 'industry' => $industry];
        }
        return $inquiries;
    }

    private static function parseNumber($val): ?float
    {
        if ($val === null) return null;
        $str = trim((string)$val);
        if (strcasecmp($str, 'N/A') === 0 || $str === '' || strcasecmp($str, 'null') === 0 || $str === '-') return null;
        $clean = str_replace([',', '$', '%', ' '], '', $str);
        if (!is_numeric($clean)) return null;
        return (float)$clean;
    }

    private function parseRowToGrid(string $rowText, string $type = 'number'): array
    {
        $cols = ['all', 'revolving', 'line_of_credit', 'open', 'installment', 'mortgage', 'auto', 'student_loans', 'unknown', 'custom'];

        $parts = explode("\t", trim($rowText));
        if (count($parts) <= 1) {
            $tokens = preg_split('/\s+/', trim($rowText));
        } else {
            $tokens = [];
            foreach ($parts as $p) {
                $trimmed = trim($p);
                if ($trimmed !== '') {
                    $tokens = array_merge($tokens, preg_split('/\s+/', $trimmed));
                }
            }
        }
        $tokens = array_values(array_filter($tokens, fn($t) => $t !== ''));

        $grid = array_fill(0, 10, null);
        if (count($tokens) >= 10) {
            for ($i = 0; $i < 10; $i++) $grid[$i] = $tokens[$i];
        } else {
            for ($i = 0; $i < count($tokens); $i++) $grid[$i] = $tokens[$i];
        }

        $result = [];
        foreach ($cols as $idx => $col) {
            $val = $grid[$idx] ?? null;
            if ($val === null || $val === '-' || strcasecmp((string)$val, 'N/A') === 0 || $val === '') {
                $result[$col] = ($type === 'date') ? null : 0;
            } else {
                if ($type === 'date') {
                    $result[$col] = (string)$val;
                } elseif ($type === 'percent') {
                    $result[$col] = (float)str_replace(['%', ' '], '', (string)$val);
                } else {
                    $result[$col] = (float)str_replace([',', '$', ' '], '', (string)$val);
                }
            }
        }
        return $result;
    }

    private function generateSummary(array $personalInfo, array $metrics, array $tradeAccounts, array $inquiries): string
    {
        $parts = [];
        $name = $personalInfo['name'] ?? null;
        $parts[] = $name ? "Credit report for {$name}" : "Credit report summary";

        $fico = $metrics['fico_score'] ?? null;
        if ($fico !== null) {
            $ficoInt = (int)$fico;
            if ($ficoInt >= 800) $parts[] = "FICO score of {$ficoInt} (Excellent)";
            elseif ($ficoInt >= 740) $parts[] = "FICO score of {$ficoInt} (Very Good)";
            elseif ($ficoInt >= 670) $parts[] = "FICO score of {$ficoInt} (Good)";
            elseif ($ficoInt >= 580) $parts[] = "FICO score of {$ficoInt} (Fair)";
            else $parts[] = "FICO score of {$ficoInt} (Poor)";
        }

        $tradeCount = count($tradeAccounts);
        if ($tradeCount > 0) {
            $openCount = count(array_filter($tradeAccounts, fn($t) => strtoupper($t['status'] ?? '') === 'OPEN'));
            $closedCount = $tradeCount - $openCount;
            $parts[] = "{$tradeCount} trade account" . ($tradeCount !== 1 ? 's' : '') . " ({$openCount} open, {$closedCount} closed)";
        }

        $util = $metrics['credit_utilization'] ?? null;
        if ($util !== null && is_numeric($util)) {
            $parts[] = "credit utilization of " . round((float)$util, 1) . "%";
        }

        $limit = $metrics['total_credit_limit'] ?? null;
        if ($limit !== null && is_numeric($limit) && (float)$limit > 0) {
            $parts[] = "total credit limit of $" . number_format((float)$limit);
        }

        $inqCount = count($inquiries);
        if ($inqCount > 0) {
            $parts[] = "{$inqCount} recent inquir" . ($inqCount === 1 ? 'y' : 'ies');
        }

        $negatives = [];
        $delinq = $metrics['delinquent_accounts'] ?? null;
        $pubRec = $metrics['public_records'] ?? null;
        $coll = $metrics['collections'] ?? null;
        if ($delinq !== null && (int)$delinq > 0) $negatives[] = (int)$delinq . " delinquent account" . ((int)$delinq > 1 ? 's' : '');
        if ($pubRec !== null && (int)$pubRec > 0) $negatives[] = (int)$pubRec . " public record" . ((int)$pubRec > 1 ? 's' : '');
        if ($coll !== null && (int)$coll > 0) $negatives[] = (int)$coll . " collection" . ((int)$coll > 1 ? 's' : '');
        if (!empty($negatives)) $parts[] = "negative items including " . implode(', ', $negatives);

        return implode('. ', $parts) . '.';
    }
}