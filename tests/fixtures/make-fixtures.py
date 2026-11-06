#!/usr/bin/env python3
"""Build the .docx word-count fixtures (spec §5.6) with known counts.

    python3 tests/fixtures/make-fixtures.py

Writes three files next to this script and prints the expected counts. Words
are whitespace-separated runs containing a letter or digit, so a lone dash is
not a word. Open each file in Word to compare: Word's status-bar count covers
the whole document (bibliography included) unless you select just the part
before the bibliography heading; its footnote/endnote count is the "include
textboxes, footnotes and endnotes" option.
"""
import json
import os
import zipfile

HERE = os.path.dirname(os.path.abspath(__file__))
W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'
R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships'

SENTENCES = [
    "The Eureka Stockade of 1854 was a turning point in colonial Victorian politics.",
    "Miners objected to the licence fee, which they paid whether or not they found gold.",
    "Historians still debate whether the rebellion was a democratic movement or a tax revolt.",
    "Peter Lalor, who led the miners, later sat in the Victorian Legislative Assembly.",
    "Primary sources from the goldfields include diaries, petitions and newspaper reports.",
    "The Ballarat Reform League charter called for manhood suffrage and the abolition of licences.",
]


def esc(s):
    return s.replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;')


def words(s):
    return sum(1 for t in s.split() if any(c.isalnum() for c in t))


def run(text, bold=False):
    rpr = '<w:rPr><w:b/></w:rPr>' if bold else ''
    return f'<w:r>{rpr}<w:t xml:space="preserve">{esc(text)}</w:t></w:r>'


def para(text='', style=None, bold=False, extra=''):
    ppr = f'<w:pPr><w:pStyle w:val="{style}"/></w:pPr>' if style else ''
    return f'<w:p>{ppr}{run(text, bold) if text else ""}{extra}</w:p>'


def note_ref(kind, nid):
    tag = 'footnoteReference' if kind == 'footnote' else 'endnoteReference'
    return f'<w:r><w:rPr><w:vertAlign w:val="superscript"/></w:rPr><w:{tag} w:id="{nid}"/></w:r>'


def notes_xml(kind, notes):
    root = 'footnotes' if kind == 'footnote' else 'endnotes'
    sep = (f'<w:{kind} w:type="separator" w:id="-1"><w:p><w:r><w:separator/></w:r></w:p></w:{kind}>'
           f'<w:{kind} w:type="continuationSeparator" w:id="0"><w:p><w:r><w:continuationSeparator/></w:r></w:p></w:{kind}>')
    body = ''.join(f'<w:{kind} w:id="{i}"><w:p>{run(" " + t)}</w:p></w:{kind}>' for i, t in enumerate(notes, 1))
    return f'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:{root} xmlns:w="{W}">{sep}{body}</w:{root}>'


STYLES = (f'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="{W}">'
          '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style>'
          '<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/><w:pPr><w:outlineLvl w:val="0"/></w:pPr><w:rPr><w:b/><w:sz w:val="32"/></w:rPr></w:style>'
          '</w:styles>')


def build(name, body_parts, footnotes=None, endnotes=None):
    doc = f'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="{W}" xmlns:r="{R}"><w:body>{"".join(body_parts)}<w:sectPr/></w:body></w:document>'
    types = ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
             '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
             '<Default Extension="xml" ContentType="application/xml"/>'
             '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
             '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
             + ('<Override PartName="/word/footnotes.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footnotes+xml"/>' if footnotes else '')
             + ('<Override PartName="/word/endnotes.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.endnotes+xml"/>' if endnotes else '')
             + '</Types>')
    rels = ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            '</Relationships>')
    doc_rels = ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                + ('<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footnotes" Target="footnotes.xml"/>' if footnotes else '')
                + ('<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/endnotes" Target="endnotes.xml"/>' if endnotes else '')
                + '</Relationships>')
    path = os.path.join(HERE, name)
    with zipfile.ZipFile(path, 'w', zipfile.ZIP_DEFLATED) as z:
        z.writestr('[Content_Types].xml', types)
        z.writestr('_rels/.rels', rels)
        z.writestr('word/_rels/document.xml.rels', doc_rels)
        z.writestr('word/document.xml', doc)
        z.writestr('word/styles.xml', STYLES)
        if footnotes:
            z.writestr('word/footnotes.xml', notes_xml('footnote', footnotes))
        if endnotes:
            z.writestr('word/endnotes.xml', notes_xml('endnote', endnotes))


expected = {}

# 1. Footnotes + a Heading 1 "Bibliography". A Heading 1 "Introduction" earlier
#    must NOT stop counting; deleted (tracked) text and a lone dash don't count.
fn = ["Weston Bate, Lucky City (Melbourne: Melbourne University Press, 1978), 62.",
      "Ballarat Times, 18 November 1854.",
      "See also the Ballarat Reform League charter, 11 November 1854."]
body = [para('Introduction', style='Heading1')]
counted = 'Introduction'
for i, s in enumerate(SENTENCES * 3):
    extra = note_ref('footnote', (i % 3) + 1) if i < 3 else ''
    body.append(para(s, extra=extra))
    counted += ' ' + s
body.append('<w:p><w:r><w:t xml:space="preserve">A tracked</w:t></w:r><w:del w:id="9" w:author="T"><w:r><w:delText> removed words here</w:delText></w:r></w:del><w:r><w:t xml:space="preserve"> change – then\tmore text.</w:t></w:r></w:p>')
counted += ' A tracked change – then more text.'
# Table cells count too.
body.append('<w:tbl><w:tr><w:tc><w:p>' + run('Licence fee 30 shillings') + '</w:p></w:tc><w:tc><w:p>' + run('per month') + '</w:p></w:tc></w:tr></w:tbl>')
counted += ' Licence fee 30 shillings per month'
bib = ['Bibliography', 'Bate, Weston. Lucky City. Melbourne University Press, 1978.', 'Blainey, Geoffrey. The Rush That Never Ended. 1963.']
body.append(para(bib[0], style='Heading1'))
body += [para(b) for b in bib[1:]]
build('footnotes-bibliography.docx', body, footnotes=fn)
expected['footnotes-bibliography.docx'] = {'body': words(counted), 'notes': sum(words(n) for n in fn), 'excluded': sum(words(b) for b in bib)}

# 2. Endnotes + a whole-paragraph bold "References:" (no heading style).
en = ["Clare Wright, The Forgotten Rebels of Eureka (Melbourne: Text, 2013), 101.",
      "Argus, 4 December 1854."]
body = []
counted = ''
for i, s in enumerate(SENTENCES * 2):
    body.append(para(s, extra=note_ref('endnote', i + 1) if i < 2 else ''))
    counted += ' ' + s
# A bold run inside a normal paragraph must not trigger the stop.
body.append('<w:p>' + run('References ', bold=True) + run('to the charter appear throughout.') + '</w:p>')
counted += ' References to the charter appear throughout.'
refs = ['References:', 'Wright, Clare. The Forgotten Rebels of Eureka. Text, 2013.']
body.append(para(refs[0], bold=True))
body.append(para(refs[1]))
build('endnotes-references.docx', body, endnotes=en)
expected['endnotes-references.docx'] = {'body': words(counted), 'notes': sum(words(n) for n in en), 'excluded': sum(words(r) for r in refs)}

# 3. Neither notes nor a bibliography.
body = []
counted = ''
for s in SENTENCES * 4:
    body.append(para(s))
    counted += ' ' + s
build('plain.docx', body)
expected['plain.docx'] = {'body': words(counted), 'notes': 0, 'excluded': 0}

for k, v in expected.items():
    v['total'] = v['body'] + v['notes']
with open(os.path.join(HERE, 'expected.json'), 'w') as f:
    json.dump(expected, f, indent=2)
print(json.dumps(expected, indent=2))
