# Application service

[English](application-services.md)

application service คือที่อยู่ของขั้นตอนที่ use-case handler ตั้งแต่สองตัวขึ้นไปต้องทำซ้ำกัน เมื่อขั้นตอนนั้นไม่ใช่กฎทางธุรกิจ คู่มือนี้พาตามขั้นตอนหนึ่งตั้งแต่ตัดสินใจว่าจะแยกออกมาใช้ร่วมกัน ไปจนถึงโค้ดที่ generator เขียน handler ที่เรียกมัน และเทสต์ ตัวกฎอยู่ใน [layers.md](../resources/boost/guidelines/layers.md) (ขั้นตอนควรอยู่ที่ไหน และสองรูปแบบของ service), [handlers.md](../resources/boost/guidelines/handlers.md) (handler ที่เรียกมัน), [exceptions.md](../resources/boost/guidelines/exceptions.md) (การปฏิเสธของมัน) และ [testing.md](../resources/boost/guidelines/testing.md) (เทสต์ของมัน) คู่มือนี้จะลิงก์ไปที่กฎแทนการเขียนซ้ำ

ตัวอย่างที่ใช้ตลอดทั้งคู่มือคือ context `Shipping` ที่มี aggregate เดียวคือ `Crate` และ context `Billing` ที่ถือ `Account` ซึ่งเป็นบัญชีที่ลังแต่ละใบถูกเรียกเก็บเงิน handler สองตัวคือ `CreateCrate` และ `ReturnCrate` ต้องการคำตอบเดียวกัน: **บัญชีของคนที่กำลังทำรายการ**

## 1. domain service, application service หรือ handler

ให้ขั้นตอนไปอยู่ในที่แรกที่ถือมันได้ ([layers.md](../resources/boost/guidelines/layers.md) หัวข้อ "Entity, domain service, application service or use-case handler")

| ที่ไหน | ถืออะไร | ตัวอย่างใน Shipping | ที่ที่ผิด |
|---|---|---|---|
| domain service | กฎทางธุรกิจที่ข้ามหลาย aggregate หรือหลาย instance ของ aggregate เดียว ภายใน context เดียว | "ลังสองใบจะมี label เดียวกันไม่ได้" ([domain-services.th.md](domain-services.th.md)) | application service: กฎจะใช้ได้แค่กับ handler ที่เรียก service นี้เท่านั้น |
| application service | ขั้นตอนที่ไม่ใช่กฎ ซึ่ง handler หลายตัวใช้ร่วมกัน: อ่าน actor, เรียกเข้า context อื่น, แปลง input เป็น domain type | "บัญชีของคนที่กำลังทำรายการ": อ่าน `UserContext` แล้วโหลด `Account` ของ `Billing` | domain service: มันรู้จัก actor หรือ context อื่นไม่ได้ ส่วนการก๊อปไว้ในทุก handler: วันที่ handler ตัวหนึ่งเปลี่ยนวิธีหาบัญชี อีกตัวจะยังใช้วิธีเดิม |
| handler | transaction, การสร้าง id, audit entry, side effect และลำดับการเรียก service | เปิด transaction, สร้าง id ของลัง, บันทึก `crate.created` | service: ถ้ามันเปิด transaction หรือบันทึก audit เอง จะรวม service สองตัวไว้ใน use case เดียวไม่ได้ |

ขั้นตอนที่มี handler ใช้อยู่ตัวเดียวให้อยู่ใน handler นั้นต่อไป ย้ายไปเป็น service เมื่อ handler ตัวที่สองต้องใช้

## 2. สร้างด้วย generator

อย่าเขียน service ด้วยมือ signature เต็ม ([layers.md](../resources/boost/guidelines/layers.md)):

```
php artisan make:application-service {Name} --domain={Context} (--data|--plain) [--repo={Aggregate}|--repo={Context}/{Aggregate}] [--actor] [--exception] [--force]
```

- `--data` และ `--plain` เลือกรูปแบบของ `handle()` ใส่อย่างใดอย่างหนึ่งเท่านั้น
- `--repo=` inject repository หนึ่งตัว จะเป็นของ context ตัวเอง (`Crate`) หรือของ context อื่น (`Billing/Account`) ก็ได้ รับได้ตัวเดียว ตัวที่สองให้เพิ่มเอง
- `--actor` inject `UserContext` ซึ่งเป็น port ของ actor
- `--exception` เขียน `{Name}Exception` ไว้ข้าง service โดย extends `ApplicationException`
- `--force` เขียน service ทับตัวที่มีอยู่แล้ว

สำหรับบัญชีของคนที่กำลังทำรายการ:

```
php artisan make:application-service ResolvePayer --domain=Shipping --plain --actor --repo=Billing/Account --exception
```

คำสั่งนี้เขียนไฟล์ใน `app/Application/Shipping/Services/ResolvePayer/`:
- `ResolvePayerService.php` ที่มี `UserContext` และ `AccountRepository` ใน constructor
- `ResolvePayerException.php`

และ `tests/Feature/Application/Shipping/Services/ResolvePayer/ResolvePayerServiceTest.php` ซึ่งอยู่ใน Feature เพราะ service inject ของที่มีแค่ container สร้างให้ได้

จนกว่าคุณจะเขียน `handle()` จะเป็นแบบนี้:

```php
public function handle(): void
{
    throw new LogicException('ResolvePayerService::handle() is not implemented yet.');
}
```

เป็น placeholder เดียวกับที่ domain service เริ่มต้น และ `kit:apply` รอข้อความเดียวกันนี้ก่อนจะสลับ service ที่มาแทนตัวเก่า เวลาเขียน body ให้แทนทั้งบรรทัด

## 3. สองรูปแบบของ `handle()`

### Plain: รับสิ่งที่ต้องใช้ คืน entity, list หรือไม่คืนอะไร

`--plain` เขียน `handle(): void` ให้แก้ parameter และ return เป็นสิ่งที่ service ต้องการ โดยไม่มี `*Data` และ `*Result`

```php
final class ResolvePayerService
{
    public function __construct(
        protected UserContext $user,
        protected AccountRepository $repo,
    ) {}

    /**
     * @throws ResolvePayerException
     */
    public function handle(): AccountEntity
    {
        $account = $this->repo->findByOwner($this->user->id());

        if ($account === null) {
            throw ResolvePayerException::noAccount($this->user->id());
        }

        return $account;
    }
}
```

### Data in, Result out: เข้าหรือออกมากกว่าหนึ่งค่า

การเสนอราคาส่งของต้องใช้ลัง บัญชีของผู้จ่าย และที่อยู่ แล้วคืนทั้งราคาและวันที่ถึง

```php
final readonly class QuoteDeliveryData
{
    public function __construct(
        public string $crateId,
        public string $postcode,
    ) {}
}

/**
 * What the service hands back to the handler calling it.
 */
final readonly class QuoteDeliveryResult
{
    public function __construct(
        public Money $price,
        public CalendarDate $arrives,
    ) {}
}
```

Data และ Result เป็น plain class แบบ `final readonly` เหมือนของ domain service ไม่มีรูปแบบ Creates เพราะการสร้าง root ต้องใช้ id ใหม่ และมีแค่ handler ที่สร้าง id ได้

## 4. exception ของ service เอง

`--exception` เขียน `{Name}Exception` ที่ extends `ApplicationException` ([exceptions.md](../resources/boost/guidelines/exceptions.md)) ให้มีหนึ่ง code และหนึ่ง named constructor ต่อหนึ่งเหตุผล:

```php
final class ResolvePayerException extends ApplicationException
{
    public const int NO_ACCOUNT = 101;

    public static function noAccount(string $ownerId): self
    {
        return new self(
            message: "No billing account belongs to [{$ownerId}].",
            code: self::NO_ACCOUNT,
            context: ['owner_id' => $ownerId],
        );
    }
}
```

controller จับมันด้วยชื่อ แบบเดียวกับที่จับการปฏิเสธของ handler exception นี้เป็นสิ่งเดียวของ service ที่ entry point อ้างถึงได้

## 5. handler ที่เรียกมัน

handler แต่ละตัว inject service แล้วเรียกมันภายใน transaction ของตัวเอง service ไม่เปิด transaction ไม่สร้าง id และไม่บันทึก audit entry ([layers.md](../resources/boost/guidelines/layers.md) หัวข้อ "Application service")

```php
final class CreateCrateHandler
{
    public function __construct(
        protected IdGenerator $ids,
        protected ResolvePayerService $payer,
        protected CrateRepository $crates,
        protected AuditLog $audit,
    ) {}

    public function __invoke(CreateCrateCommand $command): string
    {
        $id = $this->ids->next();

        return DB::transaction(function () use ($id, $command): string {
            $crate = CrateEntity::create($id, CrateLabel::from($command->label), $this->payer->handle()->id());
            $this->crates->save($crate);
            $this->audit->record('crate.created', $crate, ['label' => $crate->label()->value()]);

            return $id;
        });
    }
}
```

`ReturnCrateHandler` เรียก `$this->payer->handle()` แบบเดียวกัน handler ที่ inject service ซึ่ง inject repository นับเป็น handler ที่เขียนข้อมูล จึงต้องมี transaction และ audit entry เสมอ ([handlers.md](../resources/boost/guidelines/handlers.md))

อย่าเรียก service จาก controller, command, job หรือ listener (`layers:entry-points`) และอย่าเรียกจาก application service ตัวอื่น (`layers:app-service-calls`) ถ้า service สองตัวต้องทำงานด้วยกัน ให้ handler เรียกทั้งคู่

## 6. เทสต์

suite ขึ้นกับสิ่งที่ service inject ([testing.md](../resources/boost/guidelines/testing.md)):

| service | เทสต์ | ได้ service มาอย่างไร |
|---|---|---|
| inject อะไรก็ตาม: repository, `UserContext`, port, domain service | `tests/Feature/Application/{Context}/Services/{Name}/{Name}ServiceTest.php` | `app({Name}Service::class)` โดย bind actor ผ่าน `UserContext` |
| ไม่ inject อะไรเลย | `tests/Unit/Application/{Context}/Services/{Name}/{Name}ServiceTest.php` | `new {Name}Service` โดยไม่ใช้ Laravel |

generator ตัดสินใจครั้งเดียว ถ้า service ที่เขียนตอนแรกไม่ inject อะไรเลยมี constructor ทีหลัง ให้ย้ายเทสต์ไปที่ path เดียวกันใต้ `tests/Feature/` `testing:mirror` จะบอกจนกว่าคุณจะย้าย

เคสแรกๆ ของ `ResolvePayerServiceTest` ครอบคลุมทั้งคำตอบและการปฏิเสธ:

```php
beforeEach(function () {
    $this->service = app(ResolvePayerService::class);
});

describe('ResolvePayerService', function () {
    it('finds the account of whoever is acting', function () {
        $owner = actingAsOwner();
        $account = billingAccountOf($owner);

        expect($this->service->handle()->id())->toBe($account->id());
    });

    it('refuses an actor with no account', function () {
        actingAsOwner();

        expect(fn () => $this->service->handle())->toThrow(ResolvePayerException::class);
    });
});
```

จากนั้น `CreateCrateHandlerTest` และ `ReturnCrateHandlerTest` ต่างก็ครอบคลุม happy path ของตัวเองพร้อม audit entry และการปฏิเสธ ซึ่งหลังจากนั้นไม่มีอะไรถูกเขียน

## 7. บนหน้าจอ structure

เลือก **Add ▸ Application service** ใน context ใต้กลุ่ม Application ([structure-screen.th.md](structure-screen.th.md)) ฟอร์มรับรูปแบบ, ว่าอ่านคนที่กำลังทำรายการหรือไม่, ว่ามี exception ของตัวเองหรือไม่ และ repository ที่ inject จาก context ไหนก็ได้ จากนั้น `kit:apply` จะรัน `make:application-service` ด้วย flag ที่ตรงกัน มันส่ง `--repo` เป็น repository ตัวแรกของ context ตัวเอง หรือตัวแรกของ context อื่นถ้าไม่มีของตัวเอง repository ตัวอื่นให้เพิ่มใน constructor เอง จนกว่าจะเพิ่ม `structure:matches` จะบอก

## Checklist

- [ ] handler ตั้งแต่สองตัวทำขั้นตอนเดียวกัน และขั้นตอนนั้นไม่ใช่กฎทางธุรกิจ
- [ ] สร้างด้วย `make:application-service` ในหนึ่งในสองรูปแบบ
- [ ] เขียน `handle()` แล้ว และบรรทัด placeholder หายไป
- [ ] ไม่มี transaction, id, audit entry, handler หรือ application service ตัวอื่นอยู่ข้างใน
- [ ] การปฏิเสธของมันคือ `{Name}Exception` ที่มีหนึ่ง code และหนึ่ง named constructor ต่อหนึ่งเหตุผล
- [ ] ถูกเรียกจาก handler เท่านั้น ภายใน transaction ของ handler และมี audit entry เมื่อมันเขียนข้อมูล
- [ ] เทสต์อยู่ใน `tests/Feature` เมื่อมัน inject อะไรก็ตาม และครอบคลุมทั้งคำตอบและการปฏิเสธ
