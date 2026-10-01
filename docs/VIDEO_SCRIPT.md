# Video Script — Instructor Revenue Ledger
# Career 180 — Full Stack Laravel Hiring Quest

> **الفيديو لازم يبقى 15-20 دقيقة.**
> الأرقام الزمنية تقديرية — مش لازم تلتزم بالثانية.

---

## الجزء الأول — المقدمة (2-3 دقايق)

### إيه اللي هتقوله

> "أنا بشتغل على الـ money core لـ LMS. الفكرة إن الطلاب بيدفعوا اشتراك شهري أو ربع سنوي أو سنوي — بيدفعوا الكل من الأول. الفلوس دي لازم تتوزع على المدرسين. المشكلة الحقيقية مش التوزيع — ده سهل. المشكلة إن الـ payment provider ممكن يتهنج أو يفشل أو يحوّل الفلوس ويسكت. النظام لازم يبقى صح في كل الحالات."

> "اللي هشوفه مش full LMS — هو الـ financial kernel: allocation، append-only ledger، idempotent payout pipeline، ومـ mock provider بيكدب."

### إيه اللي مش هتقوله
- مش محتاج تتكلم عن Livewire أو Alpine منفصلين — هما جوّا Filament
- مش محتاج تشرح كل ملف في الـ codebase

---

## الجزء الثاني — Architecture (5 دقايق)

### افتح `docs/ARCHITECTURE.md` وابدأ بالرسم

> "عندنا Student بيشترك في Plan. الاشتراك بيديه access لكورسات. كل كورس عنده مدرس. لما الطالب يدفع، بنعمل SubscriptionPayment — وده بيتقسّم على المدرسين حسب كام كورس عنده الطالب مسجّل فيها. التقسيم ده بيتسجل في RevenueAllocation كـ snapshot — يعني لو الطالب اشترك في كورس جديد بعد الدفع، التوزيع القديم مش بيتغير."

---

### 2A — إزاي الفلوس بتتحسب

> "مبستخدمش float خالص. كل حاجة بـ integer — بنتعامل بالقرش مش بالجنيه. 100 جنيه = 10,000 قرش. ليه؟ عشان `0.1 + 0.2` في الـ float مش بيساوي `0.3` — وده كارثة في النظام المالي."

> "المدرسين بياخدوا 70% — ده اسمه `instructor_share_bps = 7000` يعني 7000 basis points من أصل 10,000."

**مثال توضيحي — قوله بصوت عالي:**

```
الطالب دفع: 10,000 قرش
Pool للمدرسين: 10,000 × 7,000 / 10,000 = 7,000 قرش
المنصة: 10,000 - 7,000 = 3,000 قرش

لو سارة عندها كورسين وعمر عنده كورس واحد:
سارة → 7,000 × 2/3 = 4,667 قرش
عمر  → 7,000 × 1/3 = 2,333 قرش
المجموع: 4,667 + 2,333 = 7,000 ✓
```

> "القسمة مش دايماً بتطلع عدد صحيح. بنستخدم Hamilton / Largest Remainder — بتوزّع القروش الزيادة على المدرسين اللي عندهم أكبر كسر. الضمان إن pool + المنصة دايماً = الدفعة الأصلية. مفيش قرش بيتضيع."

---

### 2B — الـ Ledger

> "بدل ما أعمل `UPDATE balance = balance + 100`، أنا بعمل append-only ledger. كل حركة فلوس بتتسجل كـ row جديد — ما بتتمسحش وما بتتعدّلش. لو حاولت تعدّل أو تمسح، الكود بيـ throw exception."

**اشرح الجدول ده:**

| النوع | العلامة | المعنى |
|---|---|---|
| `instructor_earning` | + | المدرس كسب من دفعة |
| `platform_fee` | + | نصيب المنصة |
| `refund_clawback` | − | استرداد لما الطالب رجّع فلوس |
| `payout_debit` | − | حجز فلوس قبل الدفع |
| `payout_reversal` | + | رجوع الحجز لو الدفع فشل |

> "البالانس الحقيقي هو SUM على كل الـ entries. بس بنعمل cache في `available_balance_cents` عشان مش نعمل SUM على ملايين الـ rows في كل scan. الـ cache بيتحدّث في نفس الـ transaction مع الـ ledger entry — مش في request تاني."

---

### 2C — الـ Payout State Machine

ارسم ده:

```
[pending] ──→ [processing] ──→ [succeeded] ✓
                    │
                    ├──→ [failed]   → balance بترجع، ينفع يتدفع تاني
                    │
                    └──→ [unknown]  → منتظرين نعرف الحقيقة
```

> "لما بنقرر ندفع للمدرس، بنعمل 3 حاجات في نفس الـ database transaction:
> 1. Payout row بـ status = pending
> 2. حجز الفلوس — `payout_debit` entry بقيمة سالبة، البالانس بقى صفر
> 3. `in_flight_payout_id` على المدرس عشان نمنع أي دفعة تانية
>
> بعدها بنبعت الـ job للـ queue — براّه الـ transaction."

---

### 2D — الـ Provider Timeout (أهم نقطة)

> "الـ provider ممكن يرد بـ 3 حاجات:"

```
Succeeded → status = succeeded، نشيل الـ in_flight ✓

Failed    → status = failed
           → payout_reversal entry: البالانس بترجع
           → نشيل الـ in_flight
           → المدرس ينفع ياخد دفعة جديدة

Timeout   → مش عارفين!
           → status = unknown
           → الحجز يفضل زي ما هو
           → in_flight يفضل زي ما هو
           → payouts:reconcile يسأل الـ provider بعدين
```

> "ليه Timeout مش زي Failed؟ عشان لو عملت payout_reversal وبعت للمدرس تاني — وكان الـ provider حوّل الفلوس فعلاً — المدرس هياخد ضعف المبلغ. ده الخطأ اللي النظام كله مصمم يمنعه."

> "ليه بنحجز الفلوس قبل ما نتصل بالـ provider؟ لو الـ worker مات بعد ما الـ provider نجح وقبل ما نكتب في الـ database، الـ retry هيلاقي البالانس لسه موجود ويدفع تاني. بالحجز المسبق، الـ retry هيلاقي الفلوس محجوزة وهيكمل من حيث وقف."

---

### 2E — الـ Refunds

> "لو الطالب عمل refund بـ 50%، المدرسين بيرجعوا نص نصيبهم بالظبط — مش نص الدفعة الكلية، نص الـ pool بتاعهم.

> بنستخدم cumulative approach — بنحسب إجمالي اللي المفروض يتسترد بناءً على إجمالي اللي اترجع، وبنطرح اللي اتسجل خلاص. كده لو الـ refund اتعمل على دفعات، مجموع الاسترداد صح بالظبط ومفيش قرش بيتسرب.

> لو الـ refund جه بعد ما المدرس اتدفع — البالانس بتبقى سالبة. مش بنحاول نرجع الفلوس من الـ provider. المدرس مش هياخد دفعة تانية غير لما الرصيد السالب يتسدّ من أرباحه الجاية."

---

## الجزء الثالث — Failure Scenarios Demo (5-7 دقايق)

### الإعداد قبل الفيديو

```bash
php artisan migrate:fresh --seed
```

افتح `/admin` وأوري إن في 3 مدرسين بأرصدة مختلفة.

---

### سيناريو 1 — Double Dispatch (90 ثانية)

**افتح terminal وقول:**

> "هشغّل أمر الدفع مرتين."

```bash
php artisan payouts:dispatch --min=0
php artisan payouts:dispatch --min=0
```

> "الأمر اتشغل مرتين. نروح نشوف كام Payout اتعمل."

افتح Filament وأوري إن كل مدرس عنده payout واحد بس.

> "ليه؟ عشان في أول run، المدرس اتعمله `in_flight_payout_id`. التاني run شاف الـ flag ده وعدّى من غيره."

---

### سيناريو 2 — Timeout ثم Reconcile (2 دقيقة)

**افتح `tests/Feature/ProviderUncertaintyTest.php` وأوري الـ test بس متشغّلوش — الشرح أهم من الـ terminal هنا.**

> "عندنا test بيثبت السيناريو الصعب: الـ provider حوّل الفلوس وبعدين سكت. النظام عمل إيه؟

> 1. الـ payout وقع في status = unknown
> 2. الحجز فضل زي ما هو — المدرس مش ينفع ياخد دفعة تانية
> 3. شغّلنا `payouts:reconcile` — ده بيسأل الـ provider 'إيه اللي حصل فعلاً؟'
> 4. الـ provider قال 'succeeded' — الـ payout اتحوّل لـ succeeded والـ in_flight اتشال"

```bash
php artisan test tests/Feature/ProviderUncertaintyTest.php
```

---

### سيناريو 3 — Refund بعد Payout (90 ثانية)

**افتح `tests/Feature/RefundTest.php` وأوري الـ test التاني:**

> "الطالب دفع، المدرس اتدفع، وبعدين الطالب رجّع الفلوس. إيه اللي حصل للمدرس؟"

```bash
php artisan test --filter="claws back future payouts"
```

> "البالانس بقت سالبة. -7,000 قرش. مش هياخد دفعة تانية غير لما يكسب أكتر من 7,000 في المستقبل."

---

### سيناريو 4 — Fragmented Refunds (60 ثانية)

> "مشكلة التقريب: لو الـ refund اتعمل على دفعتين، كل دفعة بتتحسب لوحدها، القروش الكسرية بتتضيع. الحل إننا بنحسب cumulative — إجمالي المفروض يترجع ناقص اللي رجع فعلاً."

```bash
php artisan test --filter="fragmented refunds"
```

---

## الجزء الرابع — Testing Strategy (2-3 دقايق)

> "الـ tests مقسّمة على 7 ملفات — كل ملف بيحمي ناحية واحدة."

**افتح `tests/Feature/` وشرح:**

| الملف | بيحمي إيه |
|---|---|
| `MoneyTest` | الـ integer arithmetic — الـ split دايماً يساوي الـ pool |
| `RevenueAllocationTest` | التوزيع صح، والـ snapshot ما بيتغيرش |
| `RefundTest` | الـ clawback صح، الـ cumulative رounding صح |
| `PayoutIdempotencyTest` | مفيش double-pay من double dispatch |
| `JobRetryTest` | مفيش double-pay من job retry |
| `ProviderUncertaintyTest` | الـ timeout ما بيتعاملش زي failure |
| `StalePayoutRecoveryTest` | الـ payouts المتعلقة بتتعالج |
| `FilamentInstructorScreenTest` | الـ admin panel شغّال والـ access control صح |

> "الـ MockPaymentProvider في الـ tests بيـ throw exception لو ما scriptتوش. ده عشان نمنع أي test يعتمد على random behavior — كل test لازم تعرف بالظبط إيه اللي هيحصل."

---

## الجزء الخامس — AI Usage (2-3 دقايق)

**افتح `docs/AI_USAGE.md` وقول:**

> "استخدمت AI في الـ scaffolding والـ boilerplate. اللي اتصممت أنا:"

**الـ decisions اللي لازم تذكرها:**

1. **Earn-on-collection مش daily accrual**
   > "500k subscription × 365 يوم = write storm كل يوم. الفلوس موجودة من الأول — مفيش سبب نؤخر الاعتراف بيها."

2. **Hold-before-call**
   > "الغلطة اللي AI اقترحتها الأول: mark paid on success. رفضتها لأن worker crash بعد الـ HTTP success وقبل الـ DB write = double pay."

3. **Timeout ≠ Failure**
   > "AI الأول كانت بتعمل reversal على الـ timeout. رفضته."

4. **Cumulative clawback مش per-refund floor**
   > "الـ bug في الـ per-refund calculation اكتشفته بـ hand-calculated example."

5. **`in_flight_payout_id` على الـ instructor**
   > "MySQL مش عنده partial unique index زي Postgres. الـ flag column هو الحل."

---

## الجزء السادس — Future Improvements (1-2 دقيقة)

> "لو ده production:"

**قوله ده:**

1. **Reserve period قبل الـ payout**
   > "دلوقتي المدرس ينفع ياخد فلوسه في نفس اليوم. في الـ production عايز 14 يوم holding period عشان أستوعب الـ refunds قبل الدفع."

2. **Redis lock فوق الـ row lock**
   > "لو عندي 500k instructor وفيه cron jobs أكتر من واحد، كل واحد فيهم هيعمل FOR UPDATE على نفس الـ rows. Redis distributed lock يمنع الـ stampede."

3. **Partition على الـ `ledger_entries`**
   > "بعد سنة أو سنتين الجدول ده هيبقى فيه عشرات الملايين من الـ rows. تقسيمه شهرياً يخلي الـ queries أسرع."

4. **Read replica لـ Filament**
   > "الـ InstructorPositionService بيعمل SUM على الـ ledger. ده fine لـ instructor واحد. مش هيشتغل لو الـ admin فتح 1000 instructor في نفس الوقت على الـ primary."

5. **MySQL concurrency test في CI**
   > "الـ SQLite في الـ tests ما بتقدرش تختبر الـ InnoDB row locks. عندنا Bash harness في `tests/Concurrency/` بيشغّل parallel processes على MySQL حقيقي."

---

## الـ Senior Bonus — Plan Change (للنقاش بس)

> "لو طالب عمل upgrade في نص الـ annual subscription:"

> "مش هعدّل على الـ payment القديم أبداً — ده history. بدل كده:"
> 1. احسب الـ unused value للـ term الحالي
> 2. احسبها كـ credit ضد الـ plan الجديد
> 3. الفرق = SubscriptionPayment جديد بـ idempotency key جديد
> 4. وزّعه بالـ weights الحالية مش القديمة
> 5. لو downgrade وعايز ترجع فلوس = Refund على الـ payment القديم بالـ clawback path الموجود

> "الـ invariant: كل حركة فلوس هي document جديد immutable. مش splicing في documents قديمة."

---

## الجمل اللي لازم تحفظها

```
"Ledger is the source of truth. The balance column is just a cache."

"We debit before we call the provider — that's what makes timeouts safe."

"Timeout is not failure. Failure reverses money. Timeout waits for the truth."

"Every money movement has a unique idempotency key — duplicates are ignored, not doubled."

"The expensive mistake is double-pay, not a delayed pay."
```

---

## الأسئلة اللي ممكن يسألوها في الـ Review

| السؤال | الإجابة |
|---|---|
| ليه مش استخدمت decimal بدل integer? | Float/decimal بيعمل rounding errors. Integer مع basis points دايماً exact. |
| إيه اللي بيمنع double-pay لو في سيرفرين؟ | `SELECT FOR UPDATE` + `in_flight_payout_id` — التاني هيلاقي الـ flag ويعدي. |
| ليه الـ `available_balance_cents` ممكن تبقى negative? | المدرس اتدفع وبعدين الطالب عمل refund. البالانس السالبة هي "الدين" — بتتسدّ من الأرباح الجاية. |
| ليه مش عملت daily accrual? | 500k × 365 = write storm. الفلوس موجودة من الأول. |
| لو غيّرت weight function، إيه اللي بيتغير؟ | الـ `AllocateSubscriptionRevenue` بس. الـ ledger والـ payout ما بيتغيروش. |
| إزاي تختبر الـ concurrency على MySQL؟ | `tests/Concurrency/mysql_concurrency_harness.sh` — parallel processes على MySQL حقيقي. |
| إيه الفرق بين `payouts:reconcile` و `payouts:recover-stale`? | `reconcile` للـ unknown — عارفين إن في مشكلة. `recover-stale` للـ pending/processing اللي اتعلقوا من غير ما نعرف. |
