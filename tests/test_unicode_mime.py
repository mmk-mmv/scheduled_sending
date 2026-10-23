"""Independent RFC email-parser checks of synthetic PHP-produced MIME only."""
import base64
from email import policy
from email.parser import BytesParser
import json
from pathlib import Path

root = Path(__file__).resolve().parent.parent / '.cache' / 'unicode-fixtures'
for kind in ('plain', 'html', 'attachment'):
    expected = json.loads((root / (kind + '.json')).read_text())
    for phase in ('draft', 'sent'):
        message = BytesParser(policy=policy.default).parsebytes((root / (kind + '-' + phase + '.eml')).read_bytes())
        assert str(message['Subject']) == expected['subject'], 'Subject changed'
        for field, name in (('From', 'Absender, Büro'), ('To', 'Müller, Büro'), ('Cc', 'Groß, Jörg')):
            assert message[field].addresses[0].display_name == name, field + ' name changed'
        if phase == 'sent':
            assert message['Bcc'] is None, 'Bcc exposed'
        else:
            assert message['Bcc'].addresses[0].display_name == 'Geheim, Änne', 'Draft Bcc lost'
        body = message.get_body(preferencelist=('html', 'plain')).get_content()
        assert body.rstrip('\r\n') == expected['body'].rstrip('\r\n'), 'Text/HTML changed'
        attachments = list(message.iter_attachments())
        if kind == 'attachment':
            assert len(attachments) == 1
            assert attachments[0].get_filename() == expected['filename'], 'RFC2231 filename changed'
            assert attachments[0].get_payload(decode=True) == base64.b64decode(expected['attachment_b64']), 'Attachment bytes changed'
        else:
            assert not attachments
        assert not message.defects, message.defects
print('PASS independent Python email parser: umlauts/ß/emoji, address names, plain text/HTML and long RFC2231 filenames/binary data in Draft and Sent MIME')
