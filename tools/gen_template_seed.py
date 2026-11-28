#!/usr/bin/env python3
# Generates database/upgrade_v83.sql — the platform legal-template catalogue.
import os

HEADER = """-- ============================================================
-- Execution 70 - platform legal template catalogue (Saudi instruments)
--
-- `document_templates` shipped in v81 with a complete schema, zero rows and no
-- reader: only includes/plan_features.php mentioned the table. This migration fills
-- the platform catalogue (firm_id IS NULL) with the standard Saudi instruments a firm
-- actually drafts, so the library is useful on a fresh install instead of an empty grid.
--
-- Idempotent: UNIQUE(firm_id, slug) cannot guard a NULL firm_id in MySQL, so v81 added
-- `global_slug` (a stored generated column equal to slug when the row is platform-wide).
-- Every row below is INSERT IGNORE on that guard, so a re-run inserts nothing.
--
-- Bodies use {{placeholders}} only. No client data and no invented case facts: a
-- template is a form, and the lawyer fills it.
-- ============================================================

SET NAMES utf8mb4;

INSERT IGNORE INTO `document_templates`
  (`category_id`, `firm_id`, `slug`, `title_ar`, `title_en`,
   `description_ar`, `description_en`, `body_ar`, `body_en`,
   `doc_type`, `fields_json`, `plan_tier`, `language`, `is_active`)
VALUES
"""

CAT = "(SELECT id FROM `template_categories` WHERE `slug`='%s' LIMIT 1)"


def q(s):
    return "'" + s.replace("'", "''") + "'"


ROWS = []


def add(cat_slug, slug, title_ar, title_en, desc_ar, desc_en, body_ar, body_en,
        doc_type, fields, tier):
    ROWS.append((CAT % cat_slug, slug, title_ar, title_en, desc_ar, desc_en,
                 body_ar, body_en, doc_type, fields, tier))


add('lawsuit', 'lawsuit-financial-claim',
    'لائحة دعوى — مطالبة مالية',
    'Statement of Claim — Financial',
    'لائحة دعوى قياسية للمطالبة بمبلغ مالي أمام المحكمة العامة، تشمل الأطراف والوقائع والطلبات.',
    'Standard financial claim before the General Court: parties, facts and prayers.',
    """بسم الله الرحمن الرحيم

لدى {{court_name}} الموقّرة

الموضوع: لائحة دعوى — مطالبة مالية
رقم القضية: {{case_number}}

أولاً — أطراف الدعوى
المدعي: {{plaintiff_name}} — هوية رقم {{plaintiff_id}}
المدعى عليه: {{defendant_name}} — هوية رقم {{defendant_id}}

ثانياً — الوقائع
{{facts}}

ثالثاً — الأسباب الشرعية والنظامية
تستند الدعوى إلى أحكام الشريعة الإسلامية والأنظمة المرعية، ولا سيما نظام المعاملات المدنية فيما يتعلق بالالتزامات التعاقدية والمسؤولية عن الضرر.

رابعاً — الطلبات
1. إلزام المدعى عليه بأن يؤدي للمدعي مبلغاً وقدره {{amount}} ريال سعودي.
2. إلزام المدعى عليه بالمصاريف وأتعاب المحاماة.
3. إصدار حكم قابل للتنفيذ بذلك.

وتقبلوا فائق الاحترام،

المحامي / الوكيل الشرعي
التاريخ: {{date}}

المرفقات:
1. صورة الهوية الوطنية للأطراف.
2. المستندات المؤيدة للوقائع.
3. الوكالة الشرعية.""",
    """In the name of Allah, the Most Gracious, the Most Merciful

Before the {{court_name}}

Subject: Statement of Claim — Financial
Case No.: {{case_number}}

I. PARTIES
Plaintiff: {{plaintiff_name}} — ID {{plaintiff_id}}
Defendant: {{defendant_name}} — ID {{defendant_id}}

II. FACTS
{{facts}}

III. LEGAL GROUNDS
The claim rests on the provisions of Islamic Sharia and applicable statutory law, in
particular the Civil Transactions Law on contractual obligations and liability for harm.

IV. PRAYERS
1. Order the defendant to pay the plaintiff the sum of {{amount}} SAR.
2. Order the defendant to bear costs and legal fees.
3. Issue an enforceable judgment accordingly.

Respectfully submitted,

Counsel / Authorised Representative
Date: {{date}}

Attachments:
1. National identity copies of the parties.
2. Documents supporting the facts.
3. Power of attorney.""",
    'lawsuit',
    '[{"key":"court_name","label_ar":"المحكمة","label_en":"Court","type":"text","required":true},'
    '{"key":"case_number","label_ar":"رقم القضية (إن وجد)","label_en":"Case number","type":"text","required":false},'
    '{"key":"plaintiff_name","label_ar":"اسم المدعي","label_en":"Plaintiff","type":"text","required":true},'
    '{"key":"plaintiff_id","label_ar":"رقم هوية المدعي","label_en":"Plaintiff ID","type":"text","required":true},'
    '{"key":"defendant_name","label_ar":"اسم المدعى عليه","label_en":"Defendant","type":"text","required":true},'
    '{"key":"defendant_id","label_ar":"رقم هوية المدعى عليه","label_en":"Defendant ID","type":"text","required":false},'
    '{"key":"amount","label_ar":"المبلغ المطالب به (ريال)","label_en":"Amount claimed (SAR)","type":"number","required":true},'
    '{"key":"facts","label_ar":"الوقائع","label_en":"Facts","type":"textarea","required":true},'
    '{"key":"date","label_ar":"التاريخ","label_en":"Date","type":"date","required":true}]',
    'free')

add('memo', 'memo-defence',
    'مذكرة جوابية — دفاع',
    'Statement of Defence',
    'مذكرة جوابية قياسية للرد على لائحة الدعوى، بترتيب الدفوع الشكلية ثم الموضوعية.',
    'Standard defence memorandum: procedural pleas, then substantive pleas.',
    """لدى {{court_name}} الموقّرة

الموضوع: مذكرة جوابية
رقم القضية: {{case_number}}

المدعي: {{plaintiff_name}}
المدعى عليه (موكلنا): {{client_name}}

أولاً — الدفوع الشكلية
{{procedural_defence}}

ثانياً — الدفوع الموضوعية
{{substantive_defence}}

ثالثاً — الطلبات
نلتمس من عدالة المحكمة:
1. عدم قبول الدعوى شكلاً لما ورد من دفوع شكلية.
2. وفي حال قبولها شكلاً، رفضها موضوعاً لعدم ثبوت ما ورد فيها.
3. إلزام المدعي بالمصاريف وأتعاب المحاماة.

وتقبلوا فائق الاحترام،

المحامي / الوكيل الشرعي
التاريخ: {{date}}""",
    """Before the {{court_name}}

Subject: Statement of Defence
Case No.: {{case_number}}

Plaintiff: {{plaintiff_name}}
Defendant (our client): {{client_name}}

I. PROCEDURAL PLEAS
{{procedural_defence}}

II. SUBSTANTIVE PLEAS
{{substantive_defence}}

III. PRAYERS
We respectfully request the Court to:
1. Dismiss the claim on procedural grounds.
2. In the alternative, dismiss it on the merits for want of proof.
3. Order the plaintiff to bear costs and legal fees.

Respectfully submitted,

Counsel / Authorised Representative
Date: {{date}}""",
    'legal_memo',
    '[{"key":"court_name","label_ar":"المحكمة","label_en":"Court","type":"text","required":true},'
    '{"key":"case_number","label_ar":"رقم القضية","label_en":"Case number","type":"text","required":true},'
    '{"key":"client_name","label_ar":"اسم الموكل (المدعى عليه)","label_en":"Client (defendant)","type":"text","required":true},'
    '{"key":"plaintiff_name","label_ar":"اسم المدعي","label_en":"Plaintiff","type":"text","required":true},'
    '{"key":"procedural_defence","label_ar":"الدفوع الشكلية","label_en":"Procedural pleas","type":"textarea","required":false},'
    '{"key":"substantive_defence","label_ar":"الدفوع الموضوعية","label_en":"Substantive pleas","type":"textarea","required":true},'
    '{"key":"date","label_ar":"التاريخ","label_en":"Date","type":"date","required":true}]',
    'free')

add('contract', 'contract-retainer',
    'عقد أتعاب محاماة',
    'Legal Retainer Agreement',
    'عقد أتعاب بين المكتب والموكل، يحدد نطاق العمل والأتعاب وطريقة السداد.',
    'Retainer agreement: scope of work, fees and payment terms.',
    """عقد أتعاب محاماة

إنه في يوم {{start_date}} تم الاتفاق بين كل من:

الطرف الأول: {{firm_name}} (المكتب)، ويمثله في هذا العقد من له الصفة النظامية.
الطرف الثاني: {{client_name}} — هوية رقم {{client_id}} (الموكل).

تمهيد:
رغب الطرف الثاني في توكيل الطرف الأول للقيام بالأعمال القانونية المبينة أدناه، وقد قبل الطرف الأول ذلك، واتفقا على ما يلي:

المادة الأولى — نطاق العمل
{{scope}}

المادة الثانية — الأتعاب
{{fee_amount}} ريال سعودي، تُسدد وفق الشروط التالية:
{{payment_terms}}

المادة الثالثة — التزامات المكتب
1. بذل العناية المهنية اللازمة في متابعة العمل.
2. إبلاغ الموكل بمستجدات العمل.
3. المحافظة على سرية معلومات الموكل.

المادة الرابعة — التزامات الموكل
1. سداد الأتعاب في مواعيدها.
2. تزويد المكتب بالمستندات والمعلومات اللازمة.
3. حضور المواعيد المتفق عليها.

المادة الخامسة — إنهاء العقد
يجوز لأي من الطرفين إنهاء العقد بإشعار كتابي، مع تسوية ما تم من أعمال حتى تاريخ الإنهاء.

المادة السادسة — القانون الواجب التطبيق
يخضع هذا العقد لأنظمة المملكة العربية السعودية، وتختص محاكمها بالنظر في أي نزاع ينشأ عنه.

حُرر هذا العقد من نسختين بيد كل طرف نسخة للعمل بموجبها.

الطرف الأول: ____________________
الطرف الثاني: ____________________""",
    """LEGAL RETAINER AGREEMENT

This agreement is made on {{start_date}} between:

First Party: {{firm_name}} (the Firm), represented by its authorised signatory.
Second Party: {{client_name}} — ID {{client_id}} (the Client).

PREAMBLE
The Client wishes to retain the Firm for the legal work set out below, and the Firm
accepts. The parties agree as follows:

ARTICLE 1 — SCOPE OF WORK
{{scope}}

ARTICLE 2 — FEES
{{fee_amount}} SAR, payable on the following terms:
{{payment_terms}}

ARTICLE 3 — FIRM OBLIGATIONS
1. Exercise due professional care in the matter.
2. Keep the Client informed of developments.
3. Preserve the confidentiality of the Client's information.

ARTICLE 4 — CLIENT OBLIGATIONS
1. Pay the fees when due.
2. Provide the documents and information required.
3. Attend agreed appointments.

ARTICLE 5 — TERMINATION
Either party may terminate on written notice, with settlement of work performed to
that date.

ARTICLE 6 — GOVERNING LAW
This agreement is governed by the laws of the Kingdom of Saudi Arabia, whose courts
have jurisdiction over any dispute arising from it.

Executed in two originals, one held by each party.

First Party: ____________________
Second Party: ____________________""",
    'contract',
    '[{"key":"firm_name","label_ar":"اسم المكتب","label_en":"Firm name","type":"text","required":true},'
    '{"key":"client_name","label_ar":"اسم الموكل","label_en":"Client name","type":"text","required":true},'
    '{"key":"client_id","label_ar":"رقم هوية الموكل","label_en":"Client ID","type":"text","required":true},'
    '{"key":"scope","label_ar":"نطاق العمل","label_en":"Scope of work","type":"textarea","required":true},'
    '{"key":"fee_amount","label_ar":"قيمة الأتعاب (ريال)","label_en":"Fee amount (SAR)","type":"number","required":true},'
    '{"key":"payment_terms","label_ar":"شروط السداد","label_en":"Payment terms","type":"textarea","required":true},'
    '{"key":"start_date","label_ar":"تاريخ البداية","label_en":"Start date","type":"date","required":true}]',
    'paid')

add('demand', 'demand-letter',
    'خطاب مطالبة',
    'Letter of Demand',
    'خطاب مطالبة رسمي قبل اللجوء إلى القضاء، بمهلة سداد محددة.',
    'Formal pre-litigation demand letter with a payment deadline.',
    """التاريخ: {{date}}

السيد / {{recipient_name}} المحترم

الموضوع: مطالبة بسداد مبلغ مالي

تحية طيبة وبعد،

بالإشارة إلى الموضوع أعلاه، ونيابةً عن موكلنا {{sender_name}}، نود إفادتكم بأن ذمتكم مشغولة لصالح موكلنا بمبلغ وقدره {{amount}} ريال سعودي، وذلك على النحو التالي:

{{reason}}

وعليه، نطلب منكم سداد المبلغ المذكور خلال مدة لا تتجاوز {{deadline_days}} يوماً من تاريخ هذا الخطاب.

وفي حال عدم السداد خلال المدة المحددة، سنضطر آسفين إلى اتخاذ كافة الإجراءات النظامية اللازمة للمطالبة بحق موكلنا، دون إشعار آخر، مع تحميلكم كافة المصاريف والأتعاب الناتجة عن ذلك.

نأمل منكم سرعة التجاوب مع هذا الخطاب تفادياً لما قد يترتب على عدمه من إجراءات.

وتقبلوا فائق الاحترام،

{{sender_name}}
المحامي / الوكيل الشرعي""",
    """Date: {{date}}

Dear {{recipient_name}},

Subject: Demand for Payment

Greetings,

With reference to the above subject, and acting on behalf of our client
{{sender_name}}, we write to advise that you are indebted to our client in the sum of
{{amount}} SAR, as follows:

{{reason}}

We therefore request payment of the said amount within {{deadline_days}} days from the
date of this letter.

Should payment not be made within that period, we will regrettably have to take all
statutory steps to enforce our client's right, without further notice, at your cost
including expenses and legal fees.

We trust you will respond promptly to avoid such measures.

Yours faithfully,

{{sender_name}}
Counsel / Authorised Representative""",
    'letter',
    '[{"key":"sender_name","label_ar":"اسم المرسل (المكتب)","label_en":"Sender (firm)","type":"text","required":true},'
    '{"key":"recipient_name","label_ar":"اسم المرسل إليه","label_en":"Recipient","type":"text","required":true},'
    '{"key":"amount","label_ar":"المبلغ (ريال)","label_en":"Amount (SAR)","type":"number","required":true},'
    '{"key":"reason","label_ar":"سبب المطالبة","label_en":"Reason for claim","type":"textarea","required":true},'
    '{"key":"deadline_days","label_ar":"مهلة السداد (أيام)","label_en":"Deadline (days)","type":"number","required":true},'
    '{"key":"date","label_ar":"التاريخ","label_en":"Date","type":"date","required":true}]',
    'free')

add('notice', 'notice-formal',
    'إنذار رسمي',
    'Formal Notice',
    'إنذار رسمي بالإخلال بالالتزام، يسبق فسخ العقد أو المطالبة القضائية.',
    'Formal notice of breach preceding termination or litigation.',
    """التاريخ: {{date}}

السيد / {{recipient_name}} المحترم

الموضوع: إنذار رسمي بالإخلال بالالتزام

تحية طيبة وبعد،

بصفتنا وكلاء شرعيون عن {{sender_name}}، نخطركم بما يلي:

{{breach_description}}

وحيث إن ما وقع منكم يُعد إخلالاً بالتزاماتكم، فإننا ننذركم بضرورة تصحيح هذا الإخلال خلال مدة لا تتجاوز {{cure_days}} يوماً من تاريخ استلامكم هذا الإنذار.

وفي حال انقضاء المدة دون تصحيح، سنعتبر العقد مفسوخاً من تلقاء نفسه دون حاجة إلى تنبيه آخر، مع تحفظ موكلنا بكافة حقوقه في المطالبة بالتعويض عن الأضرار التي لحقت به.

هذا إنذار رسمي يُعتد به في الأغراض النظامية كافة.

وتقبلوا فائق الاحترام،

{{sender_name}}
المحامي / الوكيل الشرعي""",
    """Date: {{date}}

Dear {{recipient_name}},

Subject: Formal Notice of Breach

Greetings,

Acting as authorised representatives of {{sender_name}}, we give you notice of the
following:

{{breach_description}}

As the above constitutes a breach of your obligations, we require you to remedy it
within {{cure_days}} days of receiving this notice.

If the period elapses without remedy, we shall treat the contract as terminated
automatically without further notice, and our client reserves all rights to claim
compensation for the loss suffered.

This is a formal notice, relied upon for all statutory purposes.

Yours faithfully,

{{sender_name}}
Counsel / Authorised Representative""",
    'notice',
    '[{"key":"sender_name","label_ar":"اسم المرسل","label_en":"Sender","type":"text","required":true},'
    '{"key":"recipient_name","label_ar":"اسم المرسل إليه","label_en":"Recipient","type":"text","required":true},'
    '{"key":"breach_description","label_ar":"وصف الإخلال","label_en":"Description of breach","type":"textarea","required":true},'
    '{"key":"cure_days","label_ar":"مهلة التصحيح (أيام)","label_en":"Cure period (days)","type":"number","required":true},'
    '{"key":"date","label_ar":"التاريخ","label_en":"Date","type":"date","required":true}]',
    'free')

add('poa', 'poa-general',
    'توكيل شرعي — عام',
    'Power of Attorney — General',
    'صيغة توكيل شرعي عام، تُراجع قبل التصديق من كتابة العدل.',
    'General power of attorney wording, for review before notarisation.',
    """بسم الله الرحمن الرحيم

توكيل شرعي

أنا الموكل / {{principal_name}} — هوية رقم {{principal_id}}

قد وكّلت عني السيد / {{agent_name}} — هوية رقم {{agent_id}}

وكالة شرعية في:

{{scope}}

وذلك للقيام بكل ما يلزم لتنفيذ هذا التوكيل، بما في ذلك المراجعة والمداولة والتوقيع أمام الجهات المختصة، والاستلام والتسليم، والمطالبة والمدافعة، والإقرار والإنكار، والصلح والتسوية، والتنازل والإبراء، في حدود ما ذُكر أعلاه دون سواه.

وهذا التوكيل ساري من تاريخه حتى إلغائه كتابةً.

التاريخ: {{date}}

الموكل: ____________________
الوكيل: ____________________

ملاحظة: يُصدَّق هذا التوكيل من كتابة العدل المختصة قبل العمل به.""",
    """In the name of Allah, the Most Gracious, the Most Merciful

POWER OF ATTORNEY

I, the Principal {{principal_name}} — ID {{principal_id}}

hereby appoint {{agent_name}} — ID {{agent_id}}

as my attorney in the following matters:

{{scope}}

to do all that is necessary to give effect to this power of attorney, including
attending and negotiating before the competent authorities, signing, receiving and
delivering, claiming and defending, admitting and denying, settling and compromising,
and waiving and releasing, strictly within the matters stated above.

This power of attorney is effective from its date until revoked in writing.

Date: {{date}}

Principal: ____________________
Attorney: ____________________

Note: This power of attorney must be notarised by the competent notary office before use.""",
    'power_of_attorney',
    '[{"key":"principal_name","label_ar":"اسم الموكل","label_en":"Principal","type":"text","required":true},'
    '{"key":"principal_id","label_ar":"رقم هوية الموكل","label_en":"Principal ID","type":"text","required":true},'
    '{"key":"agent_name","label_ar":"اسم الوكيل","label_en":"Agent","type":"text","required":true},'
    '{"key":"agent_id","label_ar":"رقم هوية الوكيل","label_en":"Agent ID","type":"text","required":true},'
    '{"key":"scope","label_ar":"نطاق التوكيل","label_en":"Scope of authority","type":"textarea","required":true},'
    '{"key":"date","label_ar":"التاريخ","label_en":"Date","type":"date","required":true}]',
    'paid')

add('appeal', 'appeal-objection',
    'لائحة اعتراضية',
    'Notice of Appeal',
    'لائحة اعتراض على حكم، تُبنى على أسباب الاعتراض النظامية.',
    'Appeal against a judgment, built on the statutory grounds of objection.',
    """لدى {{court_name}} الموقّرة

الموضوع: لائحة اعتراض على حكم
رقم القضية: {{case_number}}
تاريخ الحكم المعترض عليه: {{judgment_date}}

المعترض: {{appellant_name}}

أولاً — الحكم المعترض عليه
نعترض على الحكم الصادر في القضية رقم {{case_number}} بتاريخ {{judgment_date}}، ونلتمس إلغاءه أو تعديله للأسباب المبينة أدناه.

ثانياً — أسباب الاعتراض
{{grounds}}

ثالثاً — الطلبات
1. قبول الاعتراض شكلاً لتقديمه في المدة النظامية.
2. إلغاء الحكم المعترض عليه، أو تعديله بما يحقق العدالة.
3. إلزام الطرف الآخر بالمصاريف وأتعاب المحاماة.

وتقبلوا فائق الاحترام،

المحامي / الوكيل الشرعي
التاريخ: {{date}}""",
    """Before the {{court_name}}

Subject: Notice of Appeal
Case No.: {{case_number}}
Judgment appealed: {{judgment_date}}

Appellant: {{appellant_name}}

I. THE JUDGMENT APPEALED
We appeal the judgment issued in case no. {{case_number}} dated {{judgment_date}}, and
seek its annulment or amendment on the grounds below.

II. GROUNDS OF APPEAL
{{grounds}}

III. PRAYERS
1. Accept the appeal as filed within the statutory period.
2. Annul or amend the judgment in the interest of justice.
3. Order the other party to bear costs and legal fees.

Respectfully submitted,

Counsel / Authorised Representative
Date: {{date}}""",
    'appeal',
    '[{"key":"court_name","label_ar":"محكمة الاستئناف","label_en":"Court of Appeal","type":"text","required":true},'
    '{"key":"case_number","label_ar":"رقم القضية","label_en":"Case number","type":"text","required":true},'
    '{"key":"judgment_date","label_ar":"تاريخ الحكم","label_en":"Judgment date","type":"date","required":true},'
    '{"key":"appellant_name","label_ar":"اسم المعترض","label_en":"Appellant","type":"text","required":true},'
    '{"key":"grounds","label_ar":"أسباب الاعتراض","label_en":"Grounds","type":"textarea","required":true},'
    '{"key":"date","label_ar":"تاريخ اللائحة","label_en":"Date of filing","type":"date","required":true}]',
    'paid')

add('settlement', 'settlement-agreement',
    'اتفاقية تسوية ومصالحة',
    'Settlement Agreement',
    'اتفاقية تسوية ودية بين طرفين، تحدد الالتزامات المتبادلة وتُنهي النزاع.',
    'Amicable settlement: mutual obligations and an end to the dispute.',
    """اتفاقية تسوية ومصالحة

إنه في يوم {{date}} تم الاتفاق بين كل من:

الطرف الأول: {{party_a}}
الطرف الثاني: {{party_b}}

تمهيد:
نشأ بين الطرفين نزاع بشأن {{dispute_summary}}، ورغبةً منهما في إنهائه ودياً، اتفقا على ما يلي:

المادة الأولى — التسوية
يتنازل الطرفان بموجب هذه الاتفاقية عن كافة المطالبات الناشئة عن النزاع المشار إليه أعلاه.

المادة الثانية — المقابل المالي
يلتزم الطرف الثاني بأن يؤدي للطرف الأول مبلغاً وقدره {{settlement_amount}} ريال سعودي، وذلك على النحو المتفق عليه بينهما.

المادة الثالثة — الالتزامات المتبادلة
{{obligations}}

المادة الرابعة — نهائية التسوية
تُعد هذه الاتفاقية تسوية نهائية لكل ما نشأ أو قد ينشأ عن النزاع، وليس لأي من الطرفين الرجوع عنها بعد توقيعها.

المادة الخامسة — القانون الواجب التطبيق
تخضع هذه الاتفاقية لأنظمة المملكة العربية السعودية.

حُررت هذه الاتفاقية من نسختين بيد كل طرف نسخة.

الطرف الأول: ____________________
الطرف الثاني: ____________________""",
    """SETTLEMENT AGREEMENT

This agreement is made on {{date}} between:

First Party: {{party_a}}
Second Party: {{party_b}}

PREAMBLE
A dispute arose between the parties concerning {{dispute_summary}}. Wishing to resolve
it amicably, they agree as follows:

ARTICLE 1 — SETTLEMENT
The parties waive all claims arising from the dispute referred to above.

ARTICLE 2 — CONSIDERATION
The Second Party shall pay the First Party the sum of {{settlement_amount}} SAR on the
terms agreed between them.

ARTICLE 3 — MUTUAL OBLIGATIONS
{{obligations}}

ARTICLE 4 — FINALITY
This agreement is a final settlement of all matters arising or that may arise from the
dispute, and neither party may resile from it after signature.

ARTICLE 5 — GOVERNING LAW
This agreement is governed by the laws of the Kingdom of Saudi Arabia.

Executed in two originals, one held by each party.

First Party: ____________________
Second Party: ____________________""",
    'agreement',
    '[{"key":"party_a","label_ar":"الطرف الأول","label_en":"Party A","type":"text","required":true},'
    '{"key":"party_b","label_ar":"الطرف الثاني","label_en":"Party B","type":"text","required":true},'
    '{"key":"dispute_summary","label_ar":"ملخص النزاع","label_en":"Dispute summary","type":"textarea","required":true},'
    '{"key":"settlement_amount","label_ar":"مبلغ التسوية (ريال)","label_en":"Settlement amount (SAR)","type":"number","required":false},'
    '{"key":"obligations","label_ar":"الالتزامات المتبادلة","label_en":"Mutual obligations","type":"textarea","required":true},'
    '{"key":"date","label_ar":"التاريخ","label_en":"Date","type":"date","required":true}]',
    'paid')

add('acknowledgment', 'acknowledgment-debt',
    'إقرار باستلام مبلغ',
    'Acknowledgment of Receipt',
    'إقرار باستلام مبلغ مالي، يصلح دليلاً على السداد.',
    'Acknowledgment of a sum received, usable as proof of payment.',
    """إقرار باستلام مبلغ

أقر أنا / {{acknowledger_name}} — هوية رقم {{acknowledger_id}}

بأنني استلمت من السيد / {{payer_name}}

مبلغاً وقدره {{amount}} ريال سعودي

وذلك مقابل: {{purpose}}

وهذا الإقرار يُعد مخالصة تامة عن المبلغ المذكور، ولا يبقى لي قبله أي مطالبة تتعلق به.

التاريخ: {{date}}

المُقرّ: ____________________
الشاهد: ____________________""",
    """ACKNOWLEDGMENT OF RECEIPT

I, {{acknowledger_name}} — ID {{acknowledger_id}}

acknowledge that I have received from {{payer_name}}

the sum of {{amount}} SAR

in respect of: {{purpose}}

This acknowledgment is a full discharge for the said sum, and I have no remaining
claim in respect of it.

Date: {{date}}

Acknowledger: ____________________
Witness: ____________________""",
    'acknowledgment',
    '[{"key":"acknowledger_name","label_ar":"اسم المُقرّ","label_en":"Acknowledger","type":"text","required":true},'
    '{"key":"acknowledger_id","label_ar":"رقم الهوية","label_en":"ID number","type":"text","required":true},'
    '{"key":"payer_name","label_ar":"اسم الدافع","label_en":"Payer","type":"text","required":true},'
    '{"key":"amount","label_ar":"المبلغ المستلم (ريال)","label_en":"Amount received (SAR)","type":"number","required":true},'
    '{"key":"purpose","label_ar":"سبب الدفع","label_en":"Purpose","type":"textarea","required":false},'
    '{"key":"date","label_ar":"التاريخ","label_en":"Date","type":"date","required":true}]',
    'free')

add('corporate', 'corporate-resolution',
    'محضر اجتماع الجمعية العامة',
    'General Assembly Resolution',
    'محضر قرار للجمعية العامة لشركة، يشمل النصاب والقرارات المتخذة.',
    'General assembly minutes: quorum and resolutions adopted.',
    """محضر اجتماع الجمعية العامة

الشركة: {{company_name}}
السجل التجاري: {{cr_number}}
تاريخ الاجتماع: {{meeting_date}}

أولاً — الحضور
{{attendees}}

ثانياً — النصاب
تحقق النصاب النظامي لانعقاد الجمعية العامة وفقاً لنظام الشركة.

ثالثاً — القرارات
{{resolutions}}

رابعاً — الإقرار
أقر الحاضرون بصحة ما ورد في هذا المحضر، وتم توقيعه منهم.

التوقيعات:
____________________
____________________""",
    """GENERAL ASSEMBLY MINUTES

Company: {{company_name}}
Commercial registration: {{cr_number}}
Meeting date: {{meeting_date}}

I. ATTENDANCE
{{attendees}}

II. QUORUM
The statutory quorum for the general assembly was present in accordance with the
company's articles.

III. RESOLUTIONS
{{resolutions}}

IV. ATTESTATION
The attendees confirm the accuracy of these minutes and have signed below.

Signatures:
____________________
____________________""",
    'corporate',
    '[{"key":"company_name","label_ar":"اسم الشركة","label_en":"Company name","type":"text","required":true},'
    '{"key":"cr_number","label_ar":"السجل التجاري","label_en":"Commercial registration","type":"text","required":true},'
    '{"key":"meeting_date","label_ar":"تاريخ الاجتماع","label_en":"Meeting date","type":"date","required":true},'
    '{"key":"attendees","label_ar":"الحاضرون","label_en":"Attendees","type":"textarea","required":true},'
    '{"key":"resolutions","label_ar":"القرارات","label_en":"Resolutions","type":"textarea","required":true}]',
    'corporate')


def main():
    parts = [HEADER]
    for i, r in enumerate(ROWS):
        (cat_expr, slug, tar, ten, dar, den, bar, ben, dt, fj, tier) = r
        vals = ", ".join([
            cat_expr, "NULL", q(slug), q(tar), q(ten), q(dar), q(den),
            q(bar), q(ben), q(dt), q(fj), q(tier), q('ar'), "1",
        ])
        parts.append("(%s)%s\n" % (vals, ";" if i == len(ROWS) - 1 else ","))
    parts.append(
        "\n-- Nothing below updates an existing row: a re-run must never overwrite a\n"
        "-- firm's edit to a platform template. The INSERT IGNORE above is the whole\n"
        "-- write path, and `global_slug` is what makes it idempotent.\n"
    )
    out = "".join(parts)
    path = os.path.join(os.path.dirname(os.path.abspath(__file__)),
                        '..', 'database', 'upgrade_v83.sql')
    path = os.path.normpath(path)
    with open(path, 'w', encoding='utf-8') as fh:
        fh.write(out)
    print('wrote', path, len(out), 'bytes,', len(ROWS), 'templates')


if __name__ == '__main__':
    main()
