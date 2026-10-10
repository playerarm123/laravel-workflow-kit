# Domain service

[English](domain-services.md)

domain service คือที่อยู่ของกฎทางธุรกิจที่ aggregate ตัวเดียวถือไว้ไม่ได้ คู่มือนี้พาตามกฎข้อหนึ่งตั้งแต่ตัดสินใจว่าจะใช้ service ไปจนถึงโค้ดที่ generator เขียน handler ที่เรียกมัน controller และเทสต์ ตัวกฎอยู่ใน [layers.md](../resources/boost/guidelines/layers.md) (กฎควรอยู่ที่ไหน และสามรูปแบบของ service), [handlers.md](../resources/boost/guidelines/handlers.md) (handler ที่เรียกมัน), [exceptions.md](../resources/boost/guidelines/exceptions.md) (การปฏิเสธของมัน) และ [testing.md](../resources/boost/guidelines/testing.md) (เทสต์ของมัน) คู่มือนี้จะลิงก์ไปที่กฎแทนการเขียนซ้ำ

ตัวอย่างที่ใช้ตลอดทั้งคู่มือคือ context `Shipping` ที่มี aggregate เดียวคือ `Crate` ลังแต่ละใบมี value object `CrateLabel` และกฎคือ **ลังสองใบจะมี label เดียวกันไม่ได้**

## 1. entity, domain service หรือ handler

ให้กฎไปอยู่ในที่แรกที่ถือมันได้ ([layers.md](../resources/boost/guidelines/layers.md) หัวข้อ "Entity, domain service or application service")

| ที่อยู่ | ถือกฎแบบไหน | ตัวอย่างใน Shipping | ถ้าวางผิดที่ |
|---|---|---|---|
| entity | กฎที่ใช้แค่ state ของ aggregate ตัวเอง | "ลังที่ปิดผนึกแล้วเปลี่ยน label ไม่ได้": `CrateEntity::relabel()` ถามสถานะของตัวเอง | ถ้าให้ service โหลดลังมาเพื่อเช็คสถานะ กฎจะถือได้เฉพาะเมื่อผู้เรียกผ่าน service เท่านั้น |
| domain service | กฎที่คร่อมหลาย aggregate หรือหลาย instance ของ aggregate เดียว ภายใน context เดียว | "ลังสองใบมี label เดียวกันไม่ได้": ต้องเป็นสิ่งที่มองเห็นลังใบอื่นได้เท่านั้นจึงจะปฏิเสธได้ | entity: `CrateEntity` เห็นแค่ตัวเอง ไม่เห็นลังใบอื่น / handler: handler ตัวที่สองที่สร้างลัง (เช่นการ import หรือการ copy) ต้องเช็คซ้ำเอง และตัวที่ลืมเช็คก็ทำให้กฎพัง |
| handler | ทุกอย่างที่ไม่ใช่กฎทางธุรกิจ: transaction, ใครเป็นคนทำ, การสร้าง id, context อื่น, side effect, audit log | เปิด transaction, สร้าง id ของลัง, บันทึก `crate.created` | ถ้าให้ service ทำ: service ไม่เปิด transaction ไม่รู้ว่าใครเป็นคนทำ และไม่เรียก context อื่น ถ้ามันทำ จะเอา service สองตัวมาใช้ร่วมกันใน use case เดียวไม่ได้ |

กฎที่ต้องพึ่ง context อื่น (เช่น "เปิดลังได้เฉพาะตอนที่บัญชีลูกค้าใน `Billing` ยัง active อยู่") ก็ไม่ใช่ domain service เช่นกัน ให้ handler ถาม `Billing` แล้วส่งคำตอบเข้ามา

## 2. สร้างด้วย generator

ห้ามเขียน service เอง signature เต็มคือ ([layers.md](../resources/boost/guidelines/layers.md)):

```
php artisan make:domain-service {Name} --domain={Context} (--creates={Aggregate}|--data|--plain) [--repo={Aggregate}] [--exception] [--force]
```

- `--creates`, `--data` และ `--plain` เลือกรูปแบบของ `handle()` ต้องใส่หนึ่งตัวพอดี
- `--repo={Aggregate}` inject `{Aggregate}Repository` ของ context เดียวกันเข้า constructor และวางเทสต์ไว้ใน `tests/Feature` รับได้ repository เดียว ตัวที่สองต้องเพิ่มเอง
- `--exception` เขียน `{Name}Exception` ไว้ข้าง service
- `--force` เขียน service ทับตัวที่มีอยู่แล้ว

สำหรับกฎเรื่อง label:

```
php artisan make:domain-service OpenCrate --domain=Shipping --creates=Crate --repo=Crate --exception
```

คำสั่งนี้เขียนไฟล์ใน `app/Domain/Shipping/Services/OpenCrate/`:
- `OpenCrateService.php` ที่มี repository อยู่ใน constructor
- `OpenCrateData.php` ข้อมูลขาเข้าของรูปแบบ Creates
- `OpenCrateException.php`

และ `tests/Feature/Domain/Shipping/Services/OpenCrate/OpenCrateServiceTest.php`

ก่อนที่คุณจะเขียน `handle()` มันจะเป็นแบบนี้:

```php
public function handle(string $id, OpenCrateData $data): CrateEntity
{
    throw new LogicException('OpenCrateService::handle() is not implemented yet.');
}
```

throw บรรทัดนี้เป็น `LogicException` ตัวเดียวที่ domain มีได้ `exceptions:domain-throws` ยอมให้มันอยู่ และ `kit:apply` รอข้อความเดียวกันนี้ก่อนจะสลับ service ที่มาแทนตัวเก่าเข้าไป เมื่อเขียน body ให้ลบบรรทัดนี้ทั้งบรรทัด และอย่าเอาข้อความนี้ไปใช้กับอย่างอื่น

## 3. สามรูปแบบของ `handle()`

### Creates: service สร้าง aggregate ใหม่

handler สร้าง id แล้วส่งเข้ามา service เช็คกฎ สร้าง root ผ่าน `create()` ของมันเอง แล้วบันทึก

```php
final class OpenCrateService
{
    public function __construct(
        protected CrateRepository $repo,
    ) {}

    /**
     * @throws OpenCrateException
     */
    public function handle(string $id, OpenCrateData $data): CrateEntity
    {
        if ($this->repo->existsWithLabel($data->label)) {
            throw OpenCrateException::labelTaken($data->label);
        }

        $crate = CrateEntity::create($id, $data->label);
        $this->repo->save($crate);

        return $crate;
    }
}
```

```php
/**
 * What the service is handed, already in domain types.
 */
final readonly class OpenCrateData
{
    public function __construct(
        public CrateLabel $label,
    ) {}
}
```

`existsWithLabel()` เป็น finder ที่ interface ของ repository ประกาศไว้ให้ service ใช้ finder คืน entity หรือค่า scalar ที่ domain ต้องการ ([repositories.md](../resources/boost/guidelines/repositories.md)):

```php
#[Override]
public function existsWithLabel(CrateLabel $label): bool
{
    return $this->guardRead(fn (): bool => $this->newQuery()->where('label', $label->value())->exists());
}
```

### Plain: รับ id หนึ่งหรือสองตัว คืน entity, list หรือไม่คืนอะไร

การเปลี่ยน label ของลังยังอยู่ใต้กฎเดียวกัน จึงต้องใช้ service ด้วย ส่วนกฎของ entity เอง ("เปลี่ยนไม่ได้หลังปิดผนึก") ยังเป็นของ entity

```php
final class RelabelCrateService
{
    public function __construct(
        protected CrateRepository $repo,
    ) {}

    /**
     * @throws RelabelCrateException
     * @throws CrateSealedException
     */
    public function handle(CrateEntity $crate, CrateLabel $label): void
    {
        if (! $crate->label()->equals($label) && $this->repo->existsWithLabel($label)) {
            throw RelabelCrateException::labelTaken($label);
        }

        $crate->relabel($label);
        $this->repo->update($crate);
    }
}
```

`--plain` เขียน `handle(): void` ให้เปลี่ยนพารามิเตอร์และค่าที่คืนเป็นสิ่งที่ service ต้องใช้ โดยไม่มี `*Data` และ `*Result`

### Data in, Result out: ขาเข้าหรือขาออกมีมากกว่าหนึ่งค่า

การสลับ label ของลังสองใบอ่าน instance สองตัวและเขียนทั้งสองตัว

```php
final readonly class SwapCrateLabelsData
{
    public function __construct(
        public string $firstCrateId,
        public string $secondCrateId,
    ) {}
}

/**
 * What the service hands back: the aggregates it wrote, so the caller need not read them again.
 */
final readonly class SwapCrateLabelsResult
{
    public function __construct(
        public CrateEntity $first,
        public CrateEntity $second,
    ) {}
}
```

Data และ Result ของ service เป็นคลาสธรรมดาแบบ `final readonly` ที่ถือชนิดของ domain ไม่ extend `Spatie\LaravelData\Data` แบบที่ Command ของ handler ทำ เพราะ domain ไม่ใช้ framework และไม่ใช้ package ใด ๆ (`layers:domain-framework`)

## 4. exception ของ service เอง

`--exception` เขียน `{Name}Exception` ที่ extend `DomainException` โดยตรง เป็นการปฏิเสธแบบเดียวที่ไม่ extend `{Context}DomainException` ของ context ([exceptions.md](../resources/boost/guidelines/exceptions.md)) ให้มีรหัสหนึ่งตัวและ named constructor หนึ่งตัวต่อเหตุผลหนึ่งข้อ และสร้าง context ไว้ในนั้น:

```php
final class OpenCrateException extends DomainException
{
    public const int LABEL_TAKEN = 101;

    public static function labelTaken(CrateLabel $label): self
    {
        return new self(
            message: "Another crate already carries the label [{$label->value()}].",
            code: self::LABEL_TAKEN,
            context: ['label' => $label->value()],
        );
    }
}
```

กฎที่ entity เช็คเองได้ให้ throw exception ของ entity แทน (`CrateSealedException` ข้างบน)

## 5. handler ที่เรียกมัน

handler ทำสิ่งที่ service ไม่เคยทำ คือสร้าง id เปิด transaction และบันทึก audit entry ([handlers.md](../resources/boost/guidelines/handlers.md), [audit-log.md](../resources/boost/guidelines/audit-log.md)) handler ที่ inject service ซึ่งมี repository นับเป็น handler ที่เขียนข้อมูล ดังนั้น transaction และ audit entry จึงขาดไม่ได้

```
php artisan make:use-case OpenCrate --domain=Shipping --command --creates
```

```php
final class OpenCrateHandler
{
    public function __construct(
        protected IdGenerator $ids,
        protected OpenCrateService $openCrate,
        protected AuditLog $audit,
    ) {}

    public function __invoke(OpenCrateCommand $command): string
    {
        $id = $this->ids->next();

        return DB::transaction(function () use ($id, $command): string {
            $crate = $this->openCrate->handle($id, new OpenCrateData(label: CrateLabel::from($command->label)));
            $this->audit->record('crate.created', $crate, ['label' => $crate->label()->value()]);

            return $id;
        });
    }
}
```

การเช็คที่อ่านลังใบอื่นกับการเขียนที่ตามมาอยู่ใน transaction เดียวกัน แต่สอง request ยังผ่านการเช็คพร้อมกันได้ จึงต้องมี unique index บนคอลัมน์ด้วย ([migrations.md](../resources/boost/guidelines/migrations.md)) service ให้คำตอบที่ผู้ใช้เอาไปทำอะไรต่อได้ ส่วนฐานข้อมูลเป็นด่านสุดท้าย

## 6. controller ที่ catch การปฏิเสธ

controller catch exception ของ service ตามชื่อ แล้วตอบด้วย toast แบบ error ([exceptions.md](../resources/boost/guidelines/exceptions.md) หัวข้อ "Refusals in a controller") อย่างอื่นปล่อยให้ `ExceptionResponses` ตอบ

```php
public function store(StoreCrateRequest $request, OpenCrateHandler $openCrate): RedirectResponse
{
    try {
        $id = $openCrate($request->toCommand());
    } catch (OpenCrateException) {
        Inertia::flash(FlashToast::KEY, FlashToast::error(__('crates.label_taken')));

        return back();
    }

    Inertia::flash(FlashToast::KEY, FlashToast::success(__('crates.created')));

    return to_route('crates.show', $id);
}
```

ห้ามเรียก service จาก controller เพราะ entry point ใช้ของจาก domain ได้แค่ enum, value object และ exception (`layers:entry-points`)

## 7. เทสต์

suite ของเทสต์ขึ้นกับสิ่งที่ service เข้าถึง ([testing.md](../resources/boost/guidelines/testing.md)):

| service | เทสต์ | ได้ service มาอย่างไร |
|---|---|---|
| inject repository โดยตรงหรือผ่าน service อื่น | `tests/Feature/Domain/{Context}/Services/{Name}/{Name}ServiceTest.php` | `app({Name}Service::class)` บนฐานข้อมูลจริง |
| คำนวณอย่างเดียว | `tests/Unit/Domain/{Context}/Services/{Name}/{Name}ServiceTest.php` | `new {Name}Service(…)` โดยไม่มี Laravel |

generator ตัดสินครั้งเดียวจาก `--repo` ถ้า service ที่สร้างโดยไม่มี `--repo` ภายหลังเริ่มเข้าถึง repository ไม่ว่าโดยตรงหรือผ่าน service อื่น ให้ย้ายเทสต์ไปไว้ที่ path เดียวกันใต้ `tests/Feature/` และดึง service จาก container `testing:mirror` จะบอกให้ย้ายจนกว่าคุณจะย้าย

เคสแรก ๆ ของ `OpenCrateServiceTest` ครอบกฎทั้งสองทาง และอ่านผลกลับผ่าน repository:

```php
beforeEach(function () {
    $this->service = app(OpenCrateService::class);
});

describe('OpenCrateService', function () {
    it('opens a crate under a label no other crate carries', function () {
        $id = fake()->uuid();

        $this->service->handle($id, new OpenCrateData(label: CrateLabel::from('A-01')));

        expect(app(CrateRepository::class)->getById($id)->label()->value())->toBe('A-01');
    });

    it('refuses a label another crate carries', function () {
        $this->service->handle(fake()->uuid(), new OpenCrateData(label: CrateLabel::from('A-01')));

        expect(fn () => $this->service->handle(fake()->uuid(), new OpenCrateData(label: CrateLabel::from('A-01'))))
            ->toThrow(OpenCrateException::class);
    });
});
```

จากนั้น:
- `OpenCrateHandlerTest` ครอบเส้นทางปกติพร้อม audit entry (`assertDatabaseHas('audit_entries', ['event' => 'crate.created', 'subject_id' => $id])`) และการปฏิเสธ ซึ่งหลังจากนั้นต้องไม่มีลังและไม่มี entry ถูกเขียน
- `CrateController/StoreTest` ครอบ toast ของการปฏิเสธ (`assertInertiaFlash('toast.type', 'error')`) ข้างเคสของ guest, 403 และ validation

## 8. บนหน้าจอ structure

เลือก **Add ▸ Domain service** ใน context ([structure-screen.th.md](structure-screen.th.md)) ฟอร์มรับรูปแบบ aggregate ที่ service แบบ Creates สร้าง ว่ามี exception ของตัวเองหรือไม่ และ repository ที่ inject จากนั้น `kit:apply` จะรัน `make:domain-service` พร้อม flag ที่ตรงกัน มันส่ง `--repo` ให้เฉพาะ repository ตัวแรกของ context เดียวกับ service และไม่ส่งให้ service แบบ Creates เลย repository ตัวอื่นต้องเพิ่มเข้า constructor เอง ระหว่างที่ยังไม่ได้เพิ่ม `structure:matches` จะบอกไว้

## เช็คลิสต์

- [ ] กฎคร่อมหลาย aggregate หรือหลาย instance ของ aggregate เดียว ภายใน context เดียว
- [ ] สร้างด้วย `make:domain-service` ในหนึ่งในสามรูปแบบ
- [ ] เขียน `handle()` แล้ว และไม่มีบรรทัด placeholder เหลือ
- [ ] Data และ Result เป็นคลาสธรรมดาแบบ `final readonly` ที่ถือชนิดของ domain
- [ ] การปฏิเสธของมันคือ `{Name}Exception` ที่มีรหัสและ named constructor ต่อเหตุผลหนึ่งข้อ
- [ ] ถูกเรียกจาก handler เท่านั้น (หรือจาก service อื่นใน context เดียวกัน) ภายใน transaction ของ handler และมี audit entry
- [ ] controller catch การปฏิเสธตามชื่อ
- [ ] เทสต์อยู่ใน `tests/Feature` เมื่อเข้าถึง repository และครอบกฎทั้งสองทาง
