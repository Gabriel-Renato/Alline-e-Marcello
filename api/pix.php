<?php

/**
 * Gera payload PIX Copia e Cola (BR Code) com valor fixo.
 */
function pix_payload(string $key, string $name, string $city, float $amount, string $txid = 'CASAMENTOAM'): string
{
    $key = trim($key);
    $name = pix_sanitize(mb_substr($name, 0, 25));
    $city = pix_sanitize(mb_substr($city, 0, 15));
    $txid = pix_sanitize(mb_substr($txid !== '' ? $txid : '***', 0, 25));
    $amountStr = number_format(max(0, $amount), 2, '.', '');

    $merchantAccount = pix_tlv('00', 'br.gov.bcb.pix') . pix_tlv('01', $key);
    $additional = pix_tlv('05', $txid);

    $payload =
        pix_tlv('00', '01') .
        pix_tlv('26', $merchantAccount) .
        pix_tlv('52', '0000') .
        pix_tlv('53', '986') .
        pix_tlv('54', $amountStr) .
        pix_tlv('58', 'BR') .
        pix_tlv('59', $name) .
        pix_tlv('60', $city) .
        pix_tlv('62', $additional) .
        '6304';

    return $payload . pix_crc16($payload);
}

function pix_tlv(string $id, string $value): string
{
    $len = strlen($value);
    return $id . str_pad((string) $len, 2, '0', STR_PAD_LEFT) . $value;
}

function pix_sanitize(string $text): string
{
    $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    $text = preg_replace('/[^A-Za-z0-9 @#\-_.\/]/', '', (string) $text);
    return strtoupper(trim((string) $text));
}

function pix_crc16(string $payload): string
{
    $polynomial = 0x1021;
    $result = 0xFFFF;

    for ($offset = 0, $len = strlen($payload); $offset < $len; $offset++) {
        $result ^= (ord($payload[$offset]) << 8);
        for ($bit = 0; $bit < 8; $bit++) {
            if (($result & 0x8000) !== 0) {
                $result = (($result << 1) ^ $polynomial) & 0xFFFF;
            } else {
                $result = ($result << 1) & 0xFFFF;
            }
        }
    }

    return strtoupper(str_pad(dechex($result), 4, '0', STR_PAD_LEFT));
}
