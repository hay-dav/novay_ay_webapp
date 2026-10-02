import assert from 'node:assert/strict';
import { splitLinkedText } from './linkedText.js';

for (const text of [
    'Анкета https://forms.gle/QM7xKorJwrk2T8j98',
    'Сайт http://psihologfilippova.tilda.ws\nWhatsApp https://l.clck.bar/47455',
    'Подробнее (https://example.com/path).',
    'Статья https://example.com/article_(topic), www.example.com.',
    '<script>alert(1)</script> javascript:alert(1) https://example.com',
    'Без ссылки\nНовая строка',
]) {
    const parts = splitLinkedText(text);
    assert.equal(parts.map((part) => part.text).join(''), text);
    assert.ok(parts.filter((part) => part.href).every((part) => /^https?:\/\//.test(part.href)));
}
assert.equal(splitLinkedText('Анкета https://forms.gle/QM7xKorJwrk2T8j98')[1].href, 'https://forms.gle/QM7xKorJwrk2T8j98');
assert.equal(splitLinkedText('(https://example.com/path).').find((part) => part.href).href, 'https://example.com/path');
assert.equal(splitLinkedText('https://example.com/article_(topic),').find((part) => part.href).href, 'https://example.com/article_(topic)');
assert.equal(splitLinkedText('www.example.com.')[0].href, 'https://www.example.com');
assert.equal(splitLinkedText('javascript:alert(1)').filter((part) => part.href).length, 0);
assert.deepEqual(splitLinkedText(null), []);
console.log('Link rendering cases passed');
