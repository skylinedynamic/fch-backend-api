<?php

namespace App\Services\FCH\Common\Borrower;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAiParserService implements CreditProfileParserInterface
{
    private string $apiKey;
    private string $model;
    private string $baseUrl;
    private int $timeout;

    public function __construct(string $apiKey, string $model = 'gpt-4o-mini')
    {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->baseUrl = 'https://api.openai.com/v1';
        $this->timeout = 120;
    }

    public function parse(string $text): array
    {
        $prompt = $this->buildPrompt();

        try {
            $payload = [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a precise data extraction assistant specializing in credit reports. Extract ALL data from the provided text and return ONLY valid JSON. Do not include markdown formatting, code blocks, backticks, or any explanation. Return pure JSON only.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt . "\n\n---CREDIT REPORT TEXT START---\n" . $text . "\n---CREDIT REPORT TEXT END---",
                    ],
                ],
                'temperature' => 0.0,
                'max_tokens' => 16384,
            ];

            // Only add response_format for models that support it
            if (in_array($this->model, ['gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo', 'gpt-3.5-turbo'])) {
                $payload['response_format'] = ['type' => 'json_object'];
            }

            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post("{$this->baseUrl}/chat/completions", $payload);

            if (!$response->successful()) {
                $statusCode = $response->status();
                $errorBody = $response->body();
                Log::error('OpenAI API request failed', [
                    'status' => $statusCode,
                    'body' => $errorBody,
                ]);

                // Return empty result with error info in summary for user feedback
                $result = $this->emptyResult();
                $result['metrics']['summary'] = "OpenAI API error ({$statusCode}): " . substr($errorBody, 0, 200) . ". Using manual parser recommended.";
                return $result;
            }

            $responseData = $response->json();
            $content = $responseData['choices'][0]['message']['content'] ?? '';

            if (empty($content)) {
                Log::error('OpenAI parser: empty response content');
                $result = $this->emptyResult();
                $result['metrics']['summary'] = 'OpenAI returned an empty response. Please try again or switch to manual parsing.';
                return $result;
            }

            // Strip markdown code blocks if present
            $content = preg_replace('/^```(?:json)?\s*\n?/i', '', $content);
            $content = preg_replace('/\n?```\s*$/i', '', $content);
            $content = trim($content);

            $parsed = $this->parseOpenAiResponse($content);

            if ($parsed === null) {
                // Keep the reason as the summary; there is no data to summarise.
                $result = $this->emptyResult();
                $result['metrics']['summary'] = 'Failed to parse OpenAI response as JSON. Please try again or switch to manual parsing.';
                return $result;
            }

            // Generate dynamic summary (overwrite any AI-generated summary)
            $parsed['metrics']['summary'] = $this->generateSummary(
                $parsed['personal_info'] ?? [],
                $parsed['metrics'] ?? [],
                $parsed['trade_accounts'] ?? [],
                $parsed['inquiries'] ?? []
            );

            return $parsed;

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('OpenAI parser: connection timeout', ['message' => $e->getMessage()]);
            $result = $this->emptyResult();
            $result['metrics']['summary'] = 'OpenAI request timed out. Please try again or switch to manual parsing.';
            return $result;
        } catch (\Exception $e) {
            Log::error('OpenAI parser exception', [
                'message' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 500),
            ]);
            $result = $this->emptyResult();
            $result['metrics']['summary'] = 'OpenAI parsing error: ' . $e->getMessage() . '. Switch to manual parsing.';
            return $result;
        }
    }

    private function buildPrompt(): string
    {
        return <<<PROMPT
You are extracting data from an iSoftPull credit report PDF. Parse ALL data thoroughly and return it as JSON matching this exact structure. Use null for any fields not found in the text. For numeric fields use numbers, for dates use "MM/DD/YYYY" format.

CRITICAL: You MUST extract ALL trade accounts, ALL addresses, and ALL inquiries found in the report. Do NOT skip any entries.

Required JSON structure:
{
  "personal_info": {
    "name": null,
    "dob": null,
    "ssn": null,
    "email": null,
    "phone": null
  },
  "metrics": {
    "fico_score": null,
    "paydex_score": null,
    "experian_intelliscore": null,
    "equifax_score": null,
    "active_tradelines": null,
    "total_credit_limit": null,
    "credit_utilization": null,
    "recent_inquiries": null,
    "delinquent_accounts": null,
    "public_records": null,
    "collections": null,
    "delinquencies_30_60_90": null,
    "summary": "Parsed from iSoftPull credit report via OpenAI.",
    "all_accounts_analysis": {
      "total_accounts": null,
      "open_accounts": null,
      "closed_accounts": null,
      "newest_open_date": null,
      "oldest_open_date": null,
      "accounts_grid": {"all": 0, "revolving": 0, "line_of_credit": 0, "open": 0, "installment": 0, "mortgage": 0, "auto": 0, "student_loans": 0, "unknown": 0, "custom": 0},
      "open_accounts_grid": {"all": 0, "revolving": 0, "line_of_credit": 0, "open": 0, "installment": 0, "mortgage": 0, "auto": 0, "student_loans": 0, "unknown": 0, "custom": 0},
      "closed_accounts_grid": {"all": 0, "revolving": 0, "line_of_credit": 0, "open": 0, "installment": 0, "mortgage": 0, "auto": 0, "student_loans": 0, "unknown": 0, "custom": 0},
      "newest_open_date_grid": {"all": null, "revolving": null, "line_of_credit": null, "open": null, "installment": null, "mortgage": null, "auto": null, "student_loans": null, "unknown": null, "custom": null},
      "oldest_open_date_grid": {"all": null, "revolving": null, "line_of_credit": null, "open": null, "installment": null, "mortgage": null, "auto": null, "student_loans": null, "unknown": null, "custom": null}
    },
    "open_accounts_analysis": {
      "monthly_payments": null,
      "balance": null,
      "credit_limit": null,
      "available_credit": null,
      "utilization_ratio": null,
      "monthly_payments_grid": {"all": 0, "revolving": 0, "line_of_credit": 0, "open": 0, "installment": 0, "mortgage": 0, "auto": 0, "student_loans": 0, "unknown": 0, "custom": 0},
      "balance_grid": {"all": 0, "revolving": 0, "line_of_credit": 0, "open": 0, "installment": 0, "mortgage": 0, "auto": 0, "student_loans": 0, "unknown": 0, "custom": 0},
      "credit_limit_grid": {"all": 0, "revolving": 0, "line_of_credit": 0, "open": 0, "installment": 0, "mortgage": 0, "auto": 0, "student_loans": 0, "unknown": 0, "custom": 0},
      "available_credit_grid": {"all": 0, "revolving": 0, "line_of_credit": 0, "open": 0, "installment": 0, "mortgage": 0, "auto": 0, "student_loans": 0, "unknown": 0, "custom": 0},
      "utilization_ratio_grid": {"all": 0, "revolving": 0, "line_of_credit": 0, "open": 0, "installment": 0, "mortgage": 0, "auto": 0, "student_loans": 0, "unknown": 0, "custom": 0}
    }
  },
  "addresses": [],
  "trade_accounts": [],
  "inquiries": []
}

Trade account fields per entry: company, date_reported, status (OPEN/CLOSED/etc), rating, account_number, account_type, portfolio_type, remarks, payment_history_start_date, payment_frequency, responsibility, opened_date, terms, last_payment_date, balance (number), high_credit (number), limit (number), monthly_payment (number), actual_payment (number), past_due (number), months_reviewed (number), delinquencies (string like "0/0/0"), max_delinquency_date.

Address fields: type (CURRENT/PREVIOUS), street, city, state, zip, date_reported.
Inquiry fields: date, company, industry.

For the 10-column grid analysis tables, the columns are: all, revolving, line_of_credit, open, installment, mortgage, auto, student_loans, unknown, custom.

Extract EVERY trade account entry. Return empty arrays [] for addresses/trade_accounts/inquiries if none found, NOT null.
PROMPT;
    }

    /** The normalised extraction, or null when the reply holds no JSON. */
    private function parseOpenAiResponse(string $content): ?array
    {
        // Strip potential markdown wrappers
        $content = preg_replace('/^```(?:json)?\s*\n?/i', '', $content);
        $content = preg_replace('/\n?```\s*$/i', '', $content);
        $content = trim($content);

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('OpenAI parser: JSON decode failed', [
                'error' => json_last_error_msg(),
                'content_length' => strlen($content),
            ]);

            // Try to find JSON within the content
            if (preg_match('/\{.*\}/s', $content, $matches)) {
                $data = json_decode($matches[0], true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return null;
                }
            } else {
                return null;
            }
        }

        $result = $this->emptyResult();

        // Personal info
        if (isset($data['personal_info']) && is_array($data['personal_info'])) {
            foreach (['name', 'dob', 'ssn', 'email', 'phone'] as $field) {
                $result['personal_info'][$field] = $data['personal_info'][$field] ?? null;
            }
        }

        // Metrics
        if (isset($data['metrics']) && is_array($data['metrics'])) {
            $metricFields = [
                'fico_score', 'paydex_score', 'experian_intelliscore', 'equifax_score',
                'active_tradelines', 'total_credit_limit', 'credit_utilization',
                'recent_inquiries', 'delinquent_accounts', 'public_records', 'collections',
                'delinquencies_30_60_90', 'summary',
            ];
            foreach ($metricFields as $field) {
                if (isset($data['metrics'][$field])) {
                    $result['metrics'][$field] = $data['metrics'][$field];
                }
            }

            // All accounts analysis
            if (isset($data['metrics']['all_accounts_analysis']) && is_array($data['metrics']['all_accounts_analysis'])) {
                foreach ($data['metrics']['all_accounts_analysis'] as $key => $val) {
                    $result['metrics']['all_accounts_analysis'][$key] = $val;
                }
            }

            // Open accounts analysis
            if (isset($data['metrics']['open_accounts_analysis']) && is_array($data['metrics']['open_accounts_analysis'])) {
                foreach ($data['metrics']['open_accounts_analysis'] as $key => $val) {
                    $result['metrics']['open_accounts_analysis'][$key] = $val;
                }
            }
        }

        // Addresses
        if (isset($data['addresses']) && is_array($data['addresses'])) {
            $result['addresses'] = [];
            foreach ($data['addresses'] as $addr) {
                if (!is_array($addr)) continue;
                $result['addresses'][] = [
                    'type' => strtoupper($addr['type'] ?? 'CURRENT'),
                    'street' => $addr['street'] ?? '',
                    'city' => $addr['city'] ?? '',
                    'state' => $addr['state'] ?? '',
                    'zip' => $addr['zip'] ?? '',
                    'date_reported' => $addr['date_reported'] ?? null,
                ];
            }
        }

        // Trade accounts
        if (isset($data['trade_accounts']) && is_array($data['trade_accounts'])) {
            $result['trade_accounts'] = [];
            $tradeFields = [
                'company', 'date_reported', 'status', 'rating', 'account_number',
                'account_type', 'portfolio_type', 'remarks', 'payment_history_start_date',
                'payment_frequency', 'responsibility', 'opened_date', 'terms',
                'last_payment_date', 'balance', 'high_credit', 'limit', 'monthly_payment',
                'actual_payment', 'past_due', 'months_reviewed', 'delinquencies',
                'max_delinquency_date',
            ];
            foreach ($data['trade_accounts'] as $trade) {
                if (!is_array($trade)) continue;
                $item = [];
                foreach ($tradeFields as $field) {
                    $item[$field] = $trade[$field] ?? null;
                }
                if (isset($item['status'])) {
                    $item['status'] = strtoupper($item['status'] ?? 'OPEN');
                }
                // Ensure numeric fields are floats
                foreach (['balance', 'high_credit', 'limit', 'monthly_payment', 'actual_payment', 'past_due'] as $numField) {
                    if ($item[$numField] !== null) {
                        $item[$numField] = is_numeric($item[$numField]) ? (float)$item[$numField] : null;
                    }
                }
                if ($item['months_reviewed'] !== null) {
                    $item['months_reviewed'] = is_numeric($item['months_reviewed']) ? (int)$item['months_reviewed'] : null;
                }
                $result['trade_accounts'][] = $item;
            }
        }

        // Inquiries
        if (isset($data['inquiries']) && is_array($data['inquiries'])) {
            $result['inquiries'] = [];
            foreach ($data['inquiries'] as $inq) {
                if (!is_array($inq)) continue;
                $result['inquiries'][] = [
                    'date' => $inq['date'] ?? '',
                    'company' => $inq['company'] ?? '',
                    'industry' => $inq['industry'] ?? '',
                ];
            }
        }

        return $result;
    }

    private function emptyResult(): array
    {
        return [
            'personal_info' => [
                'name' => null,
                'dob' => null,
                'ssn' => null,
                'email' => null,
                'phone' => null,
            ],
            'metrics' => [
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
                'summary' => 'Parsed from iSoftPull credit report.',
                'all_accounts_analysis' => [
                    'total_accounts' => null,
                    'open_accounts' => null,
                    'closed_accounts' => null,
                    'newest_open_date' => null,
                    'oldest_open_date' => null,
                ],
                'open_accounts_analysis' => [
                    'monthly_payments' => null,
                    'balance' => null,
                    'credit_limit' => null,
                    'available_credit' => null,
                    'utilization_ratio' => null,
                ],
            ],
            'addresses' => [],
            'trade_accounts' => [],
            'inquiries' => [],
        ];
    }

    /**
     * Generate a human-readable dynamic summary from the parsed data.
     */
    private function generateSummary(array $personalInfo, array $metrics, array $tradeAccounts, array $inquiries): string
    {
        $parts = [];

        $name = $personalInfo['name'] ?? null;
        if ($name) {
            $parts[] = "Credit report for {$name}";
        } else {
            $parts[] = "Credit report summary";
        }

        $fico = $metrics['fico_score'] ?? null;
        if ($fico !== null) {
            $ficoInt = (int)$fico;
            if ($ficoInt >= 800) {
                $parts[] = "FICO score of {$ficoInt} (Excellent)";
            } elseif ($ficoInt >= 740) {
                $parts[] = "FICO score of {$ficoInt} (Very Good)";
            } elseif ($ficoInt >= 670) {
                $parts[] = "FICO score of {$ficoInt} (Good)";
            } elseif ($ficoInt >= 580) {
                $parts[] = "FICO score of {$ficoInt} (Fair)";
            } else {
                $parts[] = "FICO score of {$ficoInt} (Poor)";
            }
        }

        $tradeCount = count($tradeAccounts);
        if ($tradeCount > 0) {
            $openCount = count(array_filter($tradeAccounts, fn($t) => strtoupper($t['status'] ?? '') === 'OPEN'));
            $closedCount = $tradeCount - $openCount;
            $parts[] = "{$tradeCount} trade account" . ($tradeCount !== 1 ? 's' : '') . " ({$openCount} open, {$closedCount} closed)";
        }

        $util = $metrics['credit_utilization'] ?? null;
        if ($util !== null) {
            $utilNum = is_numeric($util) ? (float)$util : null;
            if ($utilNum !== null) {
                $parts[] = "credit utilization of " . round($utilNum, 1) . "%";
            }
        }

        $limit = $metrics['total_credit_limit'] ?? null;
        if ($limit !== null && is_numeric($limit) && (float)$limit > 0) {
            $parts[] = "total credit limit of $" . number_format((float)$limit);
        }

        $inqCount = count($inquiries);
        if ($inqCount > 0) {
            $parts[] = "{$inqCount} recent inquir" . ($inqCount === 1 ? 'y' : 'ies');
        }

        $delinq = $metrics['delinquent_accounts'] ?? null;
        $pubRec = $metrics['public_records'] ?? null;
        $coll = $metrics['collections'] ?? null;
        $negatives = [];
        if ($delinq !== null && (int)$delinq > 0) {
            $negatives[] = (int)$delinq . " delinquent account" . ((int)$delinq > 1 ? 's' : '');
        }
        if ($pubRec !== null && (int)$pubRec > 0) {
            $negatives[] = (int)$pubRec . " public record" . ((int)$pubRec > 1 ? 's' : '');
        }
        if ($coll !== null && (int)$coll > 0) {
            $negatives[] = (int)$coll . " collection" . ((int)$coll > 1 ? 's' : '');
        }
        if (!empty($negatives)) {
            $parts[] = "negative items including " . implode(', ', $negatives);
        }

        return implode('. ', $parts) . '.';
    }
}