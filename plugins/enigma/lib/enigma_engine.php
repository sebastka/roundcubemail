<?php

/*
 +-------------------------------------------------------------------------+
 | Engine of the Enigma Plugin                                             |
 |                                                                         |
 | Copyright (C) The Roundcube Dev Team                                    |
 |                                                                         |
 | Licensed under the GNU General Public License version 3 or              |
 | any later version with exceptions for skins & plugins.                  |
 | See the README file for a full license statement.                       |
 +-------------------------------------------------------------------------+
 | Author: Aleksander Machniak <alec@alec.pl>                              |
 +-------------------------------------------------------------------------+
*/

/**
 * Enigma plugin engine.
 *
 * RFC2440: OpenPGP Message Format
 * RFC3156: MIME Security with OpenPGP
 * RFC3851: S/MIME
 */
class enigma_engine
{
    private $rc;
    private $pgp_driver;
    private $smime_driver;
    private $password_time;
    private $sender;
    private $cache = [];

    public $decryptions = [];
    public $signatures = [];
    public $encrypted_parts = [];

    public const ENCRYPTED_PARTIALLY = 100;

    public const SIGN_MODE_BODY = 1;
    public const SIGN_MODE_SEPARATE = 2;
    public const SIGN_MODE_MIME = 4;

    public const ENCRYPT_MODE_BODY = 1;
    public const ENCRYPT_MODE_MIME = 2;
    public const ENCRYPT_MODE_SIGN = 4;

    /**
     * Plugin initialization.
     */
    public function __construct()
    {
        $this->rc = rcmail::get_instance();

        $this->password_time = $this->rc->config->get('enigma_password_time') * 60;

        // this will remove passwords from session after some time
        if ($this->password_time) {
            $this->get_passwords();
        }
    }

    /**
     * PGP driver initialization.
     */
    public function load_pgp_driver()
    {
        if ($this->pgp_driver) {
            return;
        }

        $driver = 'enigma_driver_' . $this->rc->config->get('enigma_pgp_driver', 'gnupg');
        $username = $this->rc->user->get_username();

        // Load driver
        $this->pgp_driver = new $driver($username);

        // Initialise driver
        $result = $this->pgp_driver->init();

        if ($result instanceof enigma_error) {
            self::raise_error($result, __LINE__, true);
        }
    }

    /**
     * S/MIME driver initialization.
     */
    public function load_smime_driver()
    {
        if ($this->smime_driver) {
            return;
        }

        $driver = 'enigma_driver_' . $this->rc->config->get('enigma_smime_driver', 'phpssl');
        $username = $this->rc->user->get_username();

        // Load driver
        $this->smime_driver = new $driver($username);

        // Initialise driver
        $result = $this->smime_driver->init();

        if ($result instanceof enigma_error) {
            self::raise_error($result, __LINE__, true);
        }
    }

    /**
     * Handler for message signing
     *
     * @param Mail_mime &$message Original message
     * @param int       $mode     Encryption mode
     *
     * @return ?enigma_error On error returns error object
     */
    public function sign_message(&$message, $mode = null)
    {
        $mime = new enigma_mime_message($message, enigma_mime_message::PGP_SIGNED);
        $from = $mime->getFromAddress();

        // find private key
        $key = $this->find_key($from, true);

        if (empty($key)) {
            return new enigma_error(enigma_error::KEYNOTFOUND);
        }

        // check if we have password for this key
        $passwords = $this->get_passwords();
        $pass = $passwords[$key->id] ?? null;

        if ($pass === null && !$this->rc->config->get('enigma_passwordless')) {
            // ask for password
            $error = ['missing' => [$key->id => $key->name]];
            return new enigma_error(enigma_error::BADPASS, '', $error);
        }

        $key->password = $pass;

        // select mode
        switch ($mode) {
            case self::SIGN_MODE_BODY:
                $pgp_mode = Crypt_GPG::SIGN_MODE_CLEAR;
                break;
            case self::SIGN_MODE_MIME:
                $pgp_mode = Crypt_GPG::SIGN_MODE_DETACHED;
                break;
            default:
                if ($mime->isMultipart()) {
                    $pgp_mode = Crypt_GPG::SIGN_MODE_DETACHED;
                } else {
                    $pgp_mode = Crypt_GPG::SIGN_MODE_CLEAR;
                }
        }

        // get message body
        if ($pgp_mode == Crypt_GPG::SIGN_MODE_CLEAR) {
            // in this mode we'll replace text part
            // with the one containing signature
            $body = $message->getTXTBody();

            $text_charset = $message->getParam('text_charset');
            $line_length = $this->rc->config->get('line_length', 72);

            // We can't use format=flowed for signed messages
            if (strpos($text_charset, 'format=flowed')) {
                [$charset, $params] = explode(';', $text_charset);
                $body = rcube_mime::unfold_flowed($body);
                $body = rcube_mime::wordwrap($body, $line_length, "\r\n", false, $charset);

                $text_charset = str_replace(";\r\n format=flowed", '', $text_charset);
            }
        } else {
            // here we'll build PGP/MIME message
            $body = $mime->getOrigBody();
        }

        // sign the body
        $result = $this->pgp_sign($body, $key, $pgp_mode);

        if ($result !== true) {
            if ($result->getCode() == enigma_error::BADPASS) {
                // ask for password
                $error = ['bad' => [$key->id => $key->name]];
                return new enigma_error(enigma_error::BADPASS, '', $error);
            }

            return $result;
        }

        // replace message body
        if ($pgp_mode == Crypt_GPG::SIGN_MODE_CLEAR) {
            $message->setTXTBody($body);
            if (!empty($text_charset)) {
                $message->setParam('text_charset', $text_charset);
            }
        } else {
            $mime->addPGPSignature($body, $this->pgp_driver->signature_algorithm());
            $message = $mime;
        }

        return null;
    }

    /**
     * Handler for message encryption
     *
     * @param Mail_mime &$message Original message
     * @param int       $mode     Encryption mode
     * @param bool      $is_draft Is draft-save action - use only sender's key for encryption
     *
     * @return ?enigma_error On error returns error object
     */
    public function encrypt_message(&$message, $mode = null, $is_draft = false)
    {
        $mime = new enigma_mime_message($message, enigma_mime_message::PGP_ENCRYPTED);

        // always use sender's key
        $from = $mime->getFromAddress();

        $sign_key = null;
        $keys = [];

        // check senders key for signing
        if ($mode & self::ENCRYPT_MODE_SIGN) {
            $sign_key = $this->find_key($from, true);

            if (empty($sign_key)) {
                return new enigma_error(enigma_error::KEYNOTFOUND);
            }

            // check if we have password for this key
            $passwords = $this->get_passwords();
            $sign_pass = $passwords[$sign_key->id] ?? null;

            if ($sign_pass === null && !$this->rc->config->get('enigma_passwordless')) {
                // ask for password
                $error = ['missing' => [$sign_key->id => $sign_key->name]];
                return new enigma_error(enigma_error::BADPASS, '', $error);
            }

            $sign_key->password = $sign_pass;
        }

        $recipients = [$from];

        // if it's not a draft we add all recipients' keys
        if (!$is_draft) {
            $recipients = array_merge($recipients, $mime->getRecipients());
        }

        $recipients = array_unique($recipients);

        // Fetch keys from external sources, if configured
        $this->sync_keys($recipients);

        // find recipient public keys
        foreach ((array) $recipients as $email) {
            if ($email == $from && $sign_key) {
                $key = $sign_key;
            } else {
                $key = $this->find_key($email);
            }

            if (empty($key)) {
                return new enigma_error(enigma_error::KEYNOTFOUND, '', ['missing' => $email]);
            }

            $keys[] = $key;
        }

        // select mode
        if ($mode & self::ENCRYPT_MODE_BODY) {
            $encrypt_mode = $mode;
        } elseif ($mode & self::ENCRYPT_MODE_MIME) {
            $encrypt_mode = $mode;
        } else {
            $encrypt_mode = $mime->isMultipart() ? self::ENCRYPT_MODE_MIME : self::ENCRYPT_MODE_BODY;
        }

        // get message body
        if ($encrypt_mode == self::ENCRYPT_MODE_BODY) {
            // in this mode we'll replace text part
            // with the one containing encrypted message
            $body = $message->getTXTBody();
        } else {
            // here we'll build PGP/MIME message
            $body = $mime->getOrigBody();
        }

        // sign the body
        $result = $this->pgp_encrypt($body, $keys, $sign_key);

        if ($result !== true) {
            if ($result->getCode() == enigma_error::BADPASS) {
                // ask for password
                $error = ['bad' => [$sign_key->id => $sign_key->name]];
                return new enigma_error(enigma_error::BADPASS, '', $error);
            }

            return $result;
        }

        // replace message body
        if ($encrypt_mode == self::ENCRYPT_MODE_BODY) {
            $message->setTXTBody($body);
        } else {
            $mime->setPGPEncryptedBody($body);
            $message = $mime;
        }

        return null;
    }

    /**
     * Handler for attaching public key to a message
     *
     * @param Mail_mime &$message Original message
     *
     * @return bool True on success, False on failure
     */
    public function attach_public_key(&$message)
    {
        $headers = $message->headers();
        $from = rcube_mime::decode_address_list($headers['From'], 1, false, null, true);
        $from = $from[1] ?? null;

        // find my key
        if ($from && ($key = $this->find_key($from, true))) {
            $pubkey_armor = $this->export_key($key->id);

            if (!$pubkey_armor instanceof enigma_error) {
                $pubkey_name = '0x' . enigma_key::format_id($key->id) . '.asc';
                $message->addAttachment($pubkey_armor, 'application/pgp-keys', $pubkey_name, false, '7bit');
                return true;
            }
        }

        return false;
    }

    /**
     * Handler for message_part_structure hook.
     * Called for every part of the message.
     *
     * @param array  $p    Original parameters
     * @param string $body Part body (will be set if used internally)
     *
     * @return array Modified parameters
     */
    public function part_structure($p, $body = null)
    {
        static $got_content = false;

        // Prevent from "decryption oracle" [CVE-2019-10740] (#6638)
        // On mail compose (edit/reply/forward) we support encrypted content only
        // in the first "content part" of the message.
        if ($got_content && $this->rc->task == 'mail' && $this->rc->action == 'compose') {
            return $p;
        }

        // Get the message/part sender
        if (!empty($p['object']->sender) && !empty($p['object']->sender['mailto'])) {
            $this->sender = $p['object']->sender['mailto'];
        }
        if (!empty($p['structure']->headers) && !empty($p['structure']->headers['from'])) {
            $from = rcube_mime::decode_address_list($p['structure']->headers['from'], 1, false);
            if (($from = current($from)) && !empty($from['mailto'])) {
                $this->sender = $from['mailto'];
            }
        }

        // Don't be tempted to support encryption in text/html parts
        // Because of EFAIL vulnerability we should never support this (#6289)

        if ($p['mimetype'] == 'text/plain' || $p['mimetype'] == 'application/pgp') {
            $this->parse_plain($p, $body);
            $got_content = true;
        } elseif ($p['mimetype'] == 'multipart/signed') {
            $this->parse_signed($p, $body);
            $got_content = true;
        } elseif ($p['mimetype'] == 'multipart/encrypted') {
            $this->parse_encrypted($p);
            $got_content = true;
        } elseif ($p['mimetype'] == 'application/pkcs7-mime') {
            $this->parse_encrypted($p);
            $got_content = true;
        } else {
            $got_content = !empty($p['structure']->type) && $p['structure']->type === 'content';
        }

        return $p;
    }

    /**
     * Handler for message_part_body hook.
     *
     * @param array $p Original parameters
     *
     * @return array Modified parameters
     */
    public function part_body($p)
    {
        /** @var rcube_message_part $part */
        $part = $p['part'];

        // encrypted attachment, see parse_plain_encrypted()
        if (!empty($part->need_decryption) && $part->body === null) {
            $this->load_pgp_driver();

            $storage = $this->rc->get_storage();
            $body = $storage->get_message_part($p['object']->uid, $part->mime_id, $part, null, null, true, 0, false);
            $result = is_string($body) ? $this->pgp_decrypt($body) : false;

            // @TODO: what to do on error?
            if ($result === true) {
                $part->body = $body;
                $part->size = strlen($body);
                $part->body_modified = true;
            }
        }

        return $p;
    }

    /**
     * Handler for plain/text message.
     *
     * @param array  &$p   Reference to hook's parameters
     * @param string $body Part body (will be set if used internally)
     */
    public function parse_plain(&$p, $body = null)
    {
        $part = $p['structure'];

        // Get message body from IMAP server
        if ($body === null) {
            $body = $this->get_part_body($p['object'], $part);
        }

        // In this way we can use fgets on string as on file handle
        // Don't use php://temp for security (body may come from an encrypted part)
        $fd = fopen('php://memory', 'r+');
        if (!$fd) {
            return;
        }

        fwrite($fd, $body);
        rewind($fd);

        $body = '';
        $prefix = '';
        $mode = '';
        $tokens = [
            'BEGIN PGP SIGNED MESSAGE' => 'signed-start',
            'END PGP SIGNATURE' => 'signed-end',
            'BEGIN PGP MESSAGE' => 'encrypted-start',
            'END PGP MESSAGE' => 'encrypted-end',
        ];
        $regexp = '/^-----(' . implode('|', array_keys($tokens)) . ')-----[\r\n]*/';

        while (($line = fgets($fd)) !== false) {
            if (strlen($line) > 5 && $line[0] === '-' && $line[4] === '-' && preg_match($regexp, $line, $m)) {
                switch ($tokens[$m[1]]) {
                    case 'signed-start':
                        $body = $line;
                        $mode = 'signed';
                        break;
                    case 'signed-end':
                        if ($mode === 'signed') {
                            $body .= $line;
                        }

                        break 2; // ignore anything after this line
                    case 'encrypted-start':
                        $body = $line;
                        $mode = 'encrypted';
                        break;
                    case 'encrypted-end':
                        if ($mode === 'encrypted') {
                            $body .= $line;
                        }

                        break 2; // ignore anything after this line
                }

                continue;
            }

            if ($mode === 'signed') {
                $body .= $line;
            } elseif ($mode === 'encrypted') {
                $body .= $line;
            } else {
                $prefix .= $line;
            }
        }

        fclose($fd);

        if ($mode === 'signed') {
            $this->parse_plain_signed($p, $body, $prefix);
        } elseif ($mode === 'encrypted') {
            $this->parse_plain_encrypted($p, $body, $prefix);
        }
    }

    /**
     * Handler for multipart/signed message.
     *
     * @param array  &$p   Reference to hook's parameters
     * @param string $body Part body (will be set if used internally)
     */
    public function parse_signed(&$p, $body = null)
    {
        $struct = $p['structure'];

        // S/MIME
        if (!empty($struct->parts[1]) && $struct->parts[1]->mimetype == 'application/pkcs7-signature') {
            $this->parse_smime_signed($p, $body);
        }
        // PGP/MIME: RFC3156
        // The multipart/signed body MUST consist of exactly two parts.
        // The first part contains the signed data in MIME canonical format,
        // including a set of appropriate content headers describing the data.
        // The second body MUST contain the PGP digital signature.  It MUST be
        // labeled with a content type of "application/pgp-signature".
        elseif (count($struct->parts) == 2
            && $struct->parts[1] && $struct->parts[1]->mimetype == 'application/pgp-signature'
        ) {
            $this->parse_pgp_signed($p, $body);
        }
    }

    /**
     * Handler for multipart/encrypted message.
     *
     * @param array &$p Reference to hook's parameters
     */
    public function parse_encrypted(&$p)
    {
        $struct = $p['structure'];

        // S/MIME
        if ($p['mimetype'] == 'application/pkcs7-mime') {
            $this->parse_smime_encrypted($p);
        }
        // PGP/MIME: RFC3156
        // The multipart/encrypted MUST consist of exactly two parts. The first
        // MIME body part must have a content type of "application/pgp-encrypted".
        // This body contains the control information.
        // The second MIME body part MUST contain the actual encrypted data.  It
        // must be labeled with a content type of "application/octet-stream".
        elseif (count($struct->parts) == 2
            && $struct->parts[0] && $struct->parts[0]->mimetype == 'application/pgp-encrypted'
            && $struct->parts[1] && $struct->parts[1]->mimetype == 'application/octet-stream'
        ) {
            $this->parse_pgp_encrypted($p);
        }
    }

    /**
     * Handler for plain signed message.
     * Excludes message and signature bodies and verifies signature.
     *
     * @param array  &$p     Reference to hook's parameters
     * @param string $body   Message (part) body
     * @param string $prefix Body prefix (additional text before the encrypted block)
     */
    private function parse_plain_signed(&$p, $body, $prefix = '')
    {
        if (!$this->rc->config->get('enigma_signatures', true)) {
            return;
        }

        $this->load_pgp_driver();
        $part = $p['structure'];

        // Verify signature
        if ($this->rc->action == 'show' || $this->rc->action == 'preview' || $this->rc->action == 'print') {
            $sig = $this->pgp_verify($body);
        }

        // In this way we can use fgets on string as on file handle
        // Don't use php://temp for security (body may come from an encrypted part)
        $fd = fopen('php://memory', 'r+');
        if (!$fd) {
            return;
        }

        fwrite($fd, $body);
        rewind($fd);

        $body = $part->body = null;
        $part->body_modified = true;

        // Extract body (and signature?)
        while (($line = fgets($fd, 1024)) !== false) {
            if ($part->body === null) {
                $part->body = '';
            } elseif (preg_match('/^-----BEGIN PGP SIGNATURE-----/', $line)) {
                break;
            } else {
                $part->body .= $line;
            }
        }

        fclose($fd);

        // Remove "Hash" Armor Headers
        $part->body = preg_replace('/^.*\r*\n\r*\n/', '', $part->body);
        // de-Dash-Escape (RFC2440)
        $part->body = preg_replace('/(^|\n)- -/', '\1-', $part->body);

        if ($prefix) {
            $part->body = $prefix . $part->body;
        }

        // Store signature data for display
        if (!empty($sig)) {
            $sig->partial = !empty($prefix);
            $this->signatures[$part->mime_id] = $sig;
        }
    }

    /**
     * Handler for PGP/MIME signed message.
     * Verifies signature.
     *
     * @param array  &$p   Reference to hook's parameters
     * @param string $body Part body (will be set if used internally)
     */
    private function parse_pgp_signed(&$p, $body = null)
    {
        if (!$this->rc->config->get('enigma_signatures', true)) {
            return;
        }

        if ($this->rc->action != 'show' && $this->rc->action != 'preview' && $this->rc->action != 'print') {
            return;
        }

        $this->load_pgp_driver();
        $struct = $p['structure'];

        $msg_part = $struct->parts[0];
        $sig_part = $struct->parts[1];

        // Get bodies
        if ($body === null) {
            if (empty($struct->body_modified)) {
                $body = $this->get_part_body($p['object'], $struct);
            }
        }

        $boundary = $struct->ctype_parameters['boundary'];

        // when it is a signed message forwarded as attachment
        // ctype_parameters property will not be set
        if (!$boundary && !empty($struct->headers['content-type'])
            && preg_match('/boundary="?([a-zA-Z0-9\'()+_,-.\/:=?]+)"?/', $struct->headers['content-type'], $m)
        ) {
            $boundary = $m[1];
        }

        // set signed part body
        [$msg_body, $sig_body] = $this->explode_signed_body($body, $boundary);

        // Verify
        if ($sig_body && $msg_body) {
            $sig = $this->pgp_verify($msg_body, $sig_body);

            // Store signature data for display
            $this->signatures[$struct->mime_id] = $sig;
            $this->signatures[$msg_part->mime_id] = $sig;
        }
    }

    /**
     * Handler for S/MIME signed message.
     * Verifies signature.
     *
     * @param array  &$p   Reference to hook's parameters
     * @param string $body Part body (will be set if used internally)
     */
    private function parse_smime_signed(&$p, $body = null)
    {
        if (!$this->rc->config->get('enigma_signatures', true)) {
            return;
        }

        // @TODO
    }

    /**
     * Handler for plain encrypted message.
     *
     * @param array  &$p     Reference to hook's parameters
     * @param string $body   Message (part) body
     * @param string $prefix Body prefix (additional text before the encrypted block)
     */
    private function parse_plain_encrypted(&$p, $body, $prefix = '')
    {
        if (!$this->rc->config->get('enigma_decryption', true)) {
            return;
        }

        $this->load_pgp_driver();
        $part = $p['structure'];

        // Decrypt
        $result = $this->pgp_decrypt($body, $signature);

        // Store decryption status
        $this->decryptions[$part->mime_id] = $result;

        // Store signature data for display
        if ($signature) {
            $this->signatures[$part->mime_id] = $signature;
        }

        // find parent part ID
        if (strpos($part->mime_id, '.')) {
            $items = explode('.', $part->mime_id);
            array_pop($items);
            $parent = implode('.', $items);
        } else {
            $parent = 0;
        }

        // Parse decrypted message
        if ($result === true) {
            $part->body = $prefix . $body;
            $part->body_modified = true;

            // it maybe PGP signed inside, verify signature
            $this->parse_plain($p, $body);

            // Remember it was decrypted
            $this->encrypted_parts[] = $part->mime_id;

            // Inform the user that only a part of the body was encrypted
            if ($prefix) {
                $this->decryptions[$part->mime_id] = self::ENCRYPTED_PARTIALLY;
            }

            // Encrypted plain message may contain encrypted attachments
            // in such case attachments have .pgp extension and type application/octet-stream.
            // This is what happens when you select "Encrypt each attachment separately
            // and send the message using inline PGP" in Thunderbird's Enigmail.

            if (!empty($p['object']->mime_parts[$parent])) {
                foreach ((array) $p['object']->mime_parts[$parent]->parts as $_part) {
                    if ($_part->disposition == 'attachment' && $_part->mimetype == 'application/octet-stream'
                        && preg_match('/^(.*)\.pgp$/i', $_part->filename, $m)
                    ) {
                        // modify filename
                        $_part->filename = $m[1];
                        // flag the part, it will be decrypted when needed
                        $_part->need_decryption = true;
                        // disable caching
                        $_part->body_modified = true;
                    }
                }
            }
        }
        // decryption failed, but the message may have already
        // been cached with the modified parts (see above),
        // let's bring the original state back
        elseif (!empty($p['object']->mime_parts[$parent])) {
            foreach ((array) $p['object']->mime_parts[$parent]->parts as $_part) {
                if ($_part->need_decryption && !preg_match('/^(.*)\.pgp$/i', $_part->filename, $m)) {
                    // modify filename
                    $_part->filename .= '.pgp';
                    // flag the part, it will be decrypted when needed
                    $_part->need_decryption = null;
                }
            }
        }
    }

    /**
     * Handler for PGP/MIME encrypted message.
     *
     * @param array &$p Reference to hook's parameters
     */
    private function parse_pgp_encrypted(&$p)
    {
        if (!$this->rc->config->get('enigma_decryption', true)) {
            return;
        }

        $this->load_pgp_driver();

        $struct = $p['structure'];
        $part = $struct->parts[1];

        // Get body
        $body = $this->get_part_body($p['object'], $part);

        // Decrypt
        $result = $this->pgp_decrypt($body, $signature);

        if ($result === true) {
            // Parse decrypted message
            $struct = $this->parse_body($body);

            // Modify original message structure
            $this->modify_structure($p, $struct, strlen($body));

            // Parse the structure (there may be encrypted/signed parts inside
            $this->part_structure([
                'object' => $p['object'],
                'structure' => $struct,
                'mimetype' => $struct->mimetype,
            ], $body);

            // Attach the decryption message to all parts
            $this->decryptions[$struct->mime_id] = $result;
            foreach ((array) $struct->parts as $sp) {
                $this->decryptions[$sp->mime_id] = $result;
                if ($signature) {
                    $this->signatures[$sp->mime_id] = $signature;
                }
            }
        } else {
            $this->decryptions[$part->mime_id] = $result;

            // Make sure decryption status message will be displayed
            $part->type = 'content';
            $p['object']->parts[] = $part;

            // don't show encrypted part on attachments list
            // don't show "cannot display encrypted message" text
            $p['abort'] = true;
        }
    }

    /**
     * Handler for S/MIME encrypted message.
     *
     * @param array &$p Reference to hook's parameters
     */
    private function parse_smime_encrypted(&$p)
    {
        if (!$this->rc->config->get('enigma_decryption', true)) {
            return;
        }

        // @TODO
    }

    /**
     * PGP signature verification.
     *
     * @param string  &$msg_body Message body
     * @param ?string $sig_body  Signature body (for MIME messages)
     *
     * @return enigma_signature|enigma_error
     */
    private function pgp_verify(&$msg_body, $sig_body = null)
    {
        // @TODO: Handle big bodies using (temp) files

        // Import sender's key from external sources, if configured
        if ($this->sender) {
            $this->sync_keys([$this->sender]);
        }

        // Get rid of possible non-ascii characters (#5962)
        $sig_body = preg_replace('/[^\x00-\x7F]/', '', (string) $sig_body);

        $sig = $this->pgp_driver->verify($msg_body, $sig_body);

        if (($sig instanceof enigma_error) && $sig->getCode() != enigma_error::KEYNOTFOUND) {
            self::raise_error($sig, __LINE__);
        }

        return $sig;
    }

    /**
     * PGP message decryption.
     *
     * @param ?string           &$msg_body  Message body
     * @param ?enigma_signature &$signature Signature verification result
     *
     * @return true|enigma_error
     */
    private function pgp_decrypt(&$msg_body, &$signature = null)
    {
        // @TODO: Handle big bodies using (temp) files

        // Import sender's key from external sources, if configured
        if ($this->sender) {
            $this->sync_keys([$this->sender]);
        }

        // Get rid of possible non-ascii characters (#5962)
        $msg_body = preg_replace('/[^\x00-\x7F]/', '', $msg_body);

        $keys = $this->get_passwords();
        $result = $this->pgp_driver->decrypt($msg_body, $keys, $signature);

        if ($result instanceof enigma_error) {
            if ($result->getCode() != enigma_error::KEYNOTFOUND) {
                self::raise_error($result, __LINE__);
            }

            return $result;
        }

        $msg_body = $result;

        return true;
    }

    /**
     * PGP message signing
     *
     * @param string     &$msg_body Message body
     * @param enigma_key $key       The key (with passphrase)
     * @param ?int       $mode      Signing mode
     *
     * @return true|enigma_error
     */
    private function pgp_sign(&$msg_body, $key, $mode = null)
    {
        // @TODO: Handle big bodies using (temp) files
        $result = $this->pgp_driver->sign($msg_body, $key, $mode);

        if ($result instanceof enigma_error) {
            if ($result->getCode() != enigma_error::KEYNOTFOUND) {
                self::raise_error($result, __LINE__);
            }

            return $result;
        }

        $msg_body = $result;

        return true;
    }

    /**
     * PGP message encrypting
     *
     * @param string  &$msg_body Message body
     * @param array   $keys      Keys (array of enigma_key objects)
     * @param ?string $sign_key  Optional signing Key ID
     * @param ?string $sign_pass Optional signing Key password
     *
     * @return true|enigma_error
     */
    private function pgp_encrypt(&$msg_body, $keys, $sign_key = null, $sign_pass = null)
    {
        // @TODO: Handle big bodies using (temp) files
        $result = $this->pgp_driver->encrypt($msg_body, $keys, $sign_key, $sign_pass);

        if ($result instanceof enigma_error) {
            if ($result->getCode() != enigma_error::KEYNOTFOUND) {
                self::raise_error($result, __LINE__);
            }

            return $result;
        }

        $msg_body = $result;

        return true;
    }

    /**
     * PGP keys listing.
     *
     * @param string $pattern Key ID/Name pattern
     *
     * @return mixed Array of keys or enigma_error
     */
    public function list_keys($pattern = '')
    {
        $this->load_pgp_driver();
        $result = $this->pgp_driver->list_keys($pattern);

        if ($result instanceof enigma_error) {
            self::raise_error($result, __LINE__);
        }

        return $result;
    }

    /**
     * Find PGP private/public key
     *
     * @param string $email    E-mail address
     * @param bool   $can_sign Need a key for signing?
     *
     * @return ?enigma_key The key
     */
    public function find_key($email, $can_sign = false)
    {
        if ($can_sign && array_key_exists($email, $this->cache)) {
            return $this->cache[$email];
        }

        $this->load_pgp_driver();
        $result = $this->pgp_driver->list_keys($email);

        if ($result instanceof enigma_error) {
            self::raise_error($result, __LINE__);
            return null;
        }

        $mode = $can_sign ? enigma_key::CAN_SIGN : enigma_key::CAN_ENCRYPT;
        $found = [];

        // check key validity and type
        foreach ($result as $key) {
            if (($subkey = $key->find_subkey($email, $mode))
                && (!$can_sign || $key->get_type() == enigma_key::TYPE_KEYPAIR)
            ) {
                $found[$subkey->get_creation_date(true)] = $key;
            }
        }

        // Use the most recent one
        if (count($found) > 1) {
            ksort($found, \SORT_NUMERIC);
        }

        $ret = count($found) > 0 ? array_pop($found) : null;

        // cache private key info for better performance
        // we can skip one list_keys() call when signing and attaching a key
        if ($can_sign) {
            $this->cache[$email] = $ret;
        }

        return $ret;
    }

    /**
     * PGP key details.
     *
     * @param string $keyid Key ID
     *
     * @return enigma_key|enigma_error
     */
    public function get_key($keyid)
    {
        $this->load_pgp_driver();
        $result = $this->pgp_driver->get_key($keyid);

        if ($result instanceof enigma_error) {
            self::raise_error($result, __LINE__);
        }

        return $result;
    }

    /**
     * PGP key delete.
     *
     * @param string $keyid Key ID
     *
     * @return enigma_error|bool True on success
     */
    public function delete_key($keyid)
    {
        $this->load_pgp_driver();
        $result = $this->pgp_driver->delete_key($keyid);

        if ($result instanceof enigma_error) {
            self::raise_error($result, __LINE__);
        }

        return $result;
    }

    /**
     * PGP keys pair generation.
     *
     * @param array $data Key pair parameters
     *
     * @return enigma_key|enigma_error
     */
    public function generate_key($data)
    {
        $this->load_pgp_driver();
        $result = $this->pgp_driver->gen_key($data);

        if ($result instanceof enigma_error) {
            self::raise_error($result, __LINE__);
        }

        return $result;
    }

    /**
     * PGP keys/certs import.
     *
     * @param mixed $content Import file name or content
     * @param bool  $isfile  True if first argument is a filename
     *
     * @return mixed Import status data array or enigma_error
     */
    public function import_key($content, $isfile = false)
    {
        $this->load_pgp_driver();
        $result = $this->pgp_driver->import($content, $isfile, $this->get_passwords());

        if ($result instanceof enigma_error) {
            self::raise_error($result, __LINE__);
        } else {
            $result['imported'] = $result['public_imported'] + $result['private_imported'];
            $result['unchanged'] = $result['public_unchanged'] + $result['private_unchanged'];
        }

        return $result;
    }

    /**
     * PGP keys/certs export.
     *
     * @param string    $key             Key ID
     * @param ?resource $fp              Optional output stream
     * @param bool      $include_private Include private key
     *
     * @return string|enigma_error|null Key content (Null if writing to a file) or enigma_error
     */
    public function export_key($key, $fp = null, $include_private = false)
    {
        $this->load_pgp_driver();
        $result = $this->pgp_driver->export($key, $include_private, $this->get_passwords());

        if ($result instanceof enigma_error) {
            self::raise_error($result, __LINE__);
            return $result;
        }

        if ($fp) {
            fwrite($fp, $result);
            return null;
        }

        return $result;
    }

    /**
     * Registers password for specified key/cert sent by the password prompt.
     */
    public function password_handler()
    {
        $keyid = rcube_utils::get_input_string('_keyid', rcube_utils::INPUT_POST);
        $passwd = rcube_utils::get_input_string('_passwd', rcube_utils::INPUT_POST, true);

        if ($keyid && strlen($passwd)) {
            $this->save_password(strtoupper($keyid), $passwd);
        }
    }

    /**
     * Saves key/cert password in user session
     */
    public function save_password($keyid, $password)
    {
        // we store passwords in session for specified time
        if (!empty($_SESSION['enigma_pass'])) {
            $config = $this->rc->decrypt($_SESSION['enigma_pass']);
            $config = unserialize($config);
        } else {
            $config = [];
        }

        $config[$keyid] = [$password, time()];

        $_SESSION['enigma_pass'] = $this->rc->encrypt(serialize($config));
    }

    /**
     * Returns currently stored passwords
     */
    public function get_passwords()
    {
        if (!empty($_SESSION['enigma_pass'])) {
            $config = $this->rc->decrypt($_SESSION['enigma_pass']);
            $config = @unserialize($config);
        }

        $threshold = $this->password_time ? time() - $this->password_time : 0;
        $keys = [];

        // delete expired passwords
        if (!empty($config)) {
            foreach ($config as $key => $value) {
                if ($threshold && $value[1] < $threshold) {
                    unset($config[$key]);
                    $modified = true;
                } else {
                    $keys[$key] = $value[0];
                }
            }

            if (!empty($modified)) {
                $_SESSION['enigma_pass'] = $this->rc->encrypt(serialize($config));
            }
        }

        return $keys;
    }

    /**
     * Get message part body.
     *
     * @param rcube_message      $msg  Message object
     * @param rcube_message_part $part Message part
     */
    private function get_part_body($msg, $part)
    {
        // @TODO: Handle big bodies using file handles

        // This is a special case when we want to get the whole body
        // using direct IMAP access, in other cases we prefer
        // rcube_message::get_part_body() as the body may be already in memory
        if (!$part->mime_id) {
            // fake the size which may be empty for multipart/* parts
            // otherwise get_message_part() below will fail
            if (!$part->size) {
                $reset = true;
                $part->size = 1;
            }

            $storage = $this->rc->get_storage();
            $body = $storage->get_message_part($msg->uid, $part->mime_id, $part,
                null, null, true, 0, false);

            if (!empty($reset)) {
                $part->size = 0;
            }
        } else {
            $body = $msg->get_part_body($part->mime_id, false);
        }

        return $body;
    }

    /**
     * Parse decrypted message body into structure
     *
     * @param string &$body Message body
     *
     * @return rcube_message_part Message structure
     */
    private function parse_body(&$body)
    {
        // Mail_mimeDecode need \r\n end-line, but gpg may return \n
        $body = (string) preg_replace('/\r?\n/', "\r\n", $body);

        // parse the body into structure
        return rcube_mime::parse_message($body);
    }

    /**
     * Replace message encrypted structure with decrypted message structure
     *
     * @param array              &$p     Hook arguments
     * @param rcube_message_part $struct Part structure
     * @param int                $size   Part size
     */
    private function modify_structure(&$p, $struct, $size = 0)
    {
        // modify mime_parts property of the message object
        $old_id = $p['structure']->mime_id;

        foreach (array_keys($p['object']->mime_parts) as $idx) {
            if (!$old_id || $idx == $old_id || str_starts_with($idx, $old_id . '.')) {
                unset($p['object']->mime_parts[$idx]);
            }
        }

        // set some part params used by Roundcube core
        $struct->headers = array_merge($p['structure']->headers, $struct->headers);
        $struct->size = $size;
        $struct->filename = $p['structure']->filename;

        // modify the new structure to be correctly handled by Roundcube
        $this->modify_structure_part($struct, $p['object'], $old_id);

        // replace old structure with the new one
        $p['structure'] = $struct;
        $p['mimetype'] = $struct->mimetype;
    }

    /**
     * Modify decrypted message part
     *
     * @param rcube_message_part $part
     * @param rcube_message      $msg
     * @param string             $old_id
     */
    private function modify_structure_part($part, $msg, $old_id)
    {
        // never cache the body
        $part->body_modified = true;
        $part->encoding = 'stream';

        // modify part identifier
        if ($old_id) {
            $part->mime_id = !$part->mime_id ? $old_id : ($old_id . '.' . $part->mime_id);
        }

        // Cache the fact it was decrypted
        $this->encrypted_parts[] = $part->mime_id;
        $msg->mime_parts[$part->mime_id] = $part;

        // modify sub-parts
        foreach ((array) $part->parts as $p) {
            $this->modify_structure_part($p, $msg, $old_id);
        }
    }

    /**
     * Extracts body and signature of multipart/signed message body
     */
    private function explode_signed_body($body, $boundary)
    {
        if (!$body) {
            return [];
        }

        $boundary = '--' . $boundary;
        $boundary_len = strlen($boundary) + 2;

        // Find boundaries
        $start = strpos($body, $boundary) + $boundary_len;
        $end = strpos($body, $boundary, $start);

        // Get signed body and signature
        $sig = substr($body, $end + $boundary_len);
        $body = substr($body, $start, $end - $start - 2);

        // Cleanup signature
        $sig = substr($sig, strpos($sig, "\r\n\r\n") + 4);
        $sig = substr($sig, 0, strpos($sig, $boundary));

        return [$body, $sig];
    }

    /**
     * Checks if specified message part is a PGP-key or S/MIME cert data
     *
     * @param rcube_message_part $part Part object
     *
     * @return bool True if part is a key/cert
     */
    public function is_keys_part($part)
    {
        // @TODO: S/MIME
        return
            // Content-Type: application/pgp-keys
            $part->mimetype == 'application/pgp-keys'
        ;
    }

    /**
     * Removes all user keys and assigned data
     *
     * @param string $username Username
     *
     * @return bool True on success, False on failure
     */
    public function delete_user_data($username)
    {
        $homedir = $this->rc->config->get('enigma_pgp_homedir', INSTALL_PATH . 'plugins/enigma/home');
        $homedir .= \DIRECTORY_SEPARATOR . $username;

        return file_exists($homedir) ? self::delete_dir($homedir) : true;
    }

    /**
     * Recursive method to remove directory with its content
     *
     * @param string $dir Directory
     */
    public static function delete_dir($dir)
    {
        // This code can be executed from command line, make sure
        // we have permissions to delete keys directory
        if (!is_writable($dir)) {
            rcube::raise_error("Unable to delete {$dir}", false, true);
            return false;
        }

        if ($content = scandir($dir)) {
            foreach ($content as $filename) {
                if ($filename != '.' && $filename != '..') {
                    $filename = $dir . \DIRECTORY_SEPARATOR . $filename;

                    if (is_dir($filename)) {
                        self::delete_dir($filename);
                    } else {
                        unlink($filename);
                    }
                }
            }

            rmdir($dir);
        }

        return true;
    }

    /**
     * Check if specified driver feature is supported
     */
    public function is_supported($feature)
    {
        $this->load_pgp_driver();

        return in_array($feature, $this->pgp_driver->capabilities());
    }

    /**
     * Raise/log (relevant) errors
     */
    protected static function raise_error($result, $line, $abort = false)
    {
        if ($result->getCode() != enigma_error::BADPASS) {
            rcube::raise_error([
                'code' => 600,
                'line' => $line,
                'message' => 'Enigma plugin: ' . $result->getMessage(),
            ], true, $abort);
        }
    }

    /**
     * Import public keys from DNS according to Kolab Web-Of-Anti-Trust
     *
     * @param array $recipients List of email addresses
     */
    protected function sync_keys($recipients)
    {
        $import = [];
        $woat = $this->rc->config->get('enigma_woat');

        if (empty($woat)) {
            return;
        }

        foreach ($recipients as $recipient) {
            if (!strpos($recipient, '@')) {
                continue;
            }

            [$local, $domain] = explode('@', $recipient);

            // Do this for configured domains only
            if (is_array($woat) && !in_array_nocase($domain, $woat)) {
                continue;
            }

            // remove parts behind a recipient delimiter ("jeroen+Trash" => "jeroen")
            $local = preg_replace('/\+.*$/', '', $local);

            $fqdn = sha1($local) . '._woat.' . $domain;

            // Fetch the TXT record(s)
            if (($records = dns_get_record($fqdn, \DNS_TXT)) === false) {
                continue;
            }

            foreach ($records as $record) {
                if (str_starts_with($record['txt'], 'v=woat1,')) {
                    $entry = explode('public_key=', $record['txt']);
                    if (count($entry) == 2) {
                        $import[] = $entry[1];
                        // For now we support only one key
                        break;
                    }
                }
            }
        }

        // Import the fetched keys
        if (!empty($import)) {
            $this->import_key(implode("\n", $import));
        }
    }
}
