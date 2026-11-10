<?php
declare(strict_types=1);

/**
 * Small SMTP client used for the platform mail configuration test.
 * Supports implicit SSL (465), STARTTLS (typically 587), and plain SMTP.
 */
function dondebailarSendSmtpMail(
    array $settings,
    string $password,
    string $recipient,
    string $subject,
    string $body
): void {
    $host = trim((string) ($settings['smtp_host'] ?? ''));
    $port = (int) ($settings['smtp_port'] ?? 0);
    $encryption = (string) ($settings['smtp_encryption'] ?? 'ssl');
    $username = (string) ($settings['smtp_username'] ?? '');
    $fromEmail = (string) ($settings['from_email'] ?? '');
    $fromName = (string) ($settings['from_name'] ?? 'DONDEBAILAR');

    if ($host === '' || $port < 1 || $port > 65535
        || !in_array($encryption, ['ssl', 'starttls', 'none'], true)
        || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)
        || !filter_var($recipient, FILTER_VALIDATE_EMAIL)
    ) {
        throw new RuntimeException('De SMTP-instellingen zijn onvolledig of ongeldig.');
    }

    $transport = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
        ],
    ]);

    $socket = @stream_socket_client(
        $transport . $host . ':' . $port,
        $errno,
        $errstr,
        20,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!is_resource($socket)) {
        throw new RuntimeException('Kan geen verbinding maken met de SMTP-server: ' . $errstr . ' (' . $errno . ').');
    }

    stream_set_timeout($socket, 20);

    $readResponse = static function (array $expected) use ($socket): string {
        $response = '';
        do {
            $line = fgets($socket, 2048);
            if ($line === false) {
                throw new RuntimeException('De SMTP-server heeft de verbinding onverwacht gesloten.');
            }
            $response .= $line;
            $code = (int) substr($line, 0, 3);
            $more = isset($line[3]) && $line[3] === '-';
        } while ($more);

        if (!in_array($code, $expected, true)) {
            throw new RuntimeException('SMTP-server antwoordde met ' . trim($response));
        }
        return $response;
    };

    $command = static function (string $line, array $expected = [250]) use ($socket, $readResponse): string {
        if (fwrite($socket, $line . "\r\n") === false) {
            throw new RuntimeException('Kon geen opdracht naar de SMTP-server sturen.');
        }
        return $readResponse($expected);
    };

    try {
        $readResponse([220]);
        $clientName = gethostname() ?: 'dondebailar.eu';
        $clientName = preg_replace('/[^a-zA-Z0-9.-]/', '', $clientName) ?: 'dondebailar.eu';
        $command('EHLO ' . $clientName, [250]);

        if ($encryption === 'starttls') {
            $command('STARTTLS', [220]);
            $crypto = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($crypto !== true) {
                throw new RuntimeException('STARTTLS-beveiliging kon niet worden ingeschakeld.');
            }
            $command('EHLO ' . $clientName, [250]);
        }

        if ($username !== '') {
            if ($password === '') {
                throw new RuntimeException('Er is een SMTP-gebruikersnaam ingesteld, maar geen SMTP-wachtwoord opgeslagen.');
            }
            $command('AUTH LOGIN', [334]);
            $command(base64_encode($username), [334]);
            $command(base64_encode($password), [235]);
        }

        $command('MAIL FROM:<' . $fromEmail . '>', [250]);
        $command('RCPT TO:<' . $recipient . '>', [250, 251]);
        $command('DATA', [354]);

        $encodedName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $messageId = bin2hex(random_bytes(12)) . '@' . (parse_url('https://' . $host, PHP_URL_HOST) ?: 'dondebailar.eu');
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . $encodedName . ' <' . $fromEmail . '>',
            'To: <' . $recipient . '>',
            'Subject: ' . $encodedSubject,
            'Message-ID: <' . $messageId . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = preg_replace('/\n\./', "\n..", $body) ?? $body;
        if (str_starts_with($body, '.')) {
            $body = '.' . $body;
        }
        $data = implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n", "\r\n", $body) . "\r\n.";
        if (fwrite($socket, $data . "\r\n") === false) {
            throw new RuntimeException('Het testbericht kon niet worden verzonden.');
        }
        $readResponse([250]);
        $command('QUIT', [221]);
    } finally {
        fclose($socket);
    }
}
