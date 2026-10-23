<?php
// Modified by Scheduled Sending contributors, 2026-10-23. GPL-3.0; see LICENSE and CREDITS.md.
trait scheduled_sending_mime_trait
{
    private function ss_encode_headers(array $headers): array
    {
        $text_headers = ['subject','from','to','cc','bcc','sender','reply-to','resent-from','resent-to','resent-cc','resent-bcc','resent-sender','resent-reply-to','comments','keywords','content-description'];
        foreach ($headers as $name => $value) {
            if (!in_array(strtolower($name), $text_headers, true) || !preg_match('/[^\x00-\x7F]/', $value)) continue;
            if (!mb_check_encoding($value, 'UTF-8')) throw new RuntimeException('Invalid UTF-8 message header');
            // The standard Roundcube dependency understands address syntax and
            // encodes display names without encoding the actual mailbox address.
            $headers[$name] = Mail_mimePart::encodeHeader($name, $value, 'UTF-8', 'base64', "\r\n");
        }
        return $headers;
    }

    private function ss_encode_message_headers(string $raw): string
    {
        [$headers, $body] = $this->_ss_split_raw_message($raw);
        $encoded = $this->ss_encode_headers($headers);
        // Preserve existing MIME bodies, attachments, transfer encodings and
        // already RFC2047-encoded headers; never guess or undo text encodings.
        return $encoded === $headers ? $raw : $this->_ss_headers_to_raw($encoded, $body, true);
    }

    private function ss_attachment_part($data, $filename, $type): string
    {
        $filename = (string) $filename;
        if (!mb_check_encoding($filename, 'UTF-8') || preg_match('/[\r\n\x00]/', $filename)) throw new RuntimeException('Invalid attachment filename');
        if (!is_string($type) || !preg_match('~^[a-zA-Z0-9!#$&^_.+-]+/[a-zA-Z0-9!#$&^_.+-]+$~D', $type)) $type = 'application/octet-stream';
        // PEAR Mail_Mime emits RFC2231 filename/name parameters, including
        // continuation lines for long UTF-8 names, and encodes binary bytes once.
        $part = new Mail_mimePart((string) $data, ['content_type' => $type, 'encoding' => 'base64',
            'disposition' => 'attachment', 'filename' => $filename, 'headers_charset' => 'UTF-8', 'eol' => "\r\n"]);
        $encoded = $part->encode();
        if (!is_array($encoded)) throw new RuntimeException('Attachment MIME encoding failed');
        return $this->_ss_headers_to_raw($encoded['headers'], $encoded['body']) . "\r\n";
    }
}
