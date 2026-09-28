# Laravel High-Scale Modular Monolith Architecture

> Evolved from the existing **SOLID + Repository + Service + DTO** architecture document.
> Target: production platform for **10M+ registered users**, **100K+ DAU**, thousands of concurrent users, high API traffic.

**Style of system:** Modular Monolith first. Extract microservices only when a module’s scale, team, or failure domain forces it.

This document **keeps** the original principles and application-layer patterns, then **adds** infrastructure, data, cache, queues, security, observability, CI/CD, and a migration path.

---

## Table of Contents

### A. Application layer (preserved and extended)

1. [Architecture Overview](#1-architecture-overview)
2. [Folder Structure](#2-folder-structure)
3. [DTO — Data Transfer Object](#3-dto--data-transfer-object)
4. [FormRequest — Validation Layer](#4-formrequest--validation-layer)
5. [Service Interface (DIP)](#5-service-interface-dip)
6. [Service — Business Logic](#6-service--business-logic)
7. [Repository Interface (DIP)](#7-repository-interface-dip)
8. [Repository Implementation](#8-repository-implementation)
9. [Controller — Thin Layer](#9-controller--thin-layer)
10. [Model — Relations Only](#10-model--relations-only)
11. [ServiceProvider — Bindings](#11-serviceprovider--bindings)
12. [Event System](#12-event-system)
13. [Notification System](#13-notification-system)
14. [Outgoing Webhooks](#14-outgoing-webhooks)
15. [Payment Integration](#15-payment-integration)
16. [API Gateway — User and Service Auth](#16-api-gateway--user-and-service-auth)
17. [SOLID Principles Summary](#17-solid-principles-summary)

### B. Enterprise scale (new)

18. [Infrastructure Architecture](#18-infrastructure-architecture)
19. [Laravel Application Improvements](#19-laravel-application-improvements)
20. [Database Scalability](#20-database-scalability)
21. [Caching Strategy](#21-caching-strategy)
22. [Queue Architecture](#22-queue-architecture)
23. [API Architecture](#23-api-architecture)
24. [Performance Optimization](#24-performance-optimization)
25. [Security Architecture](#25-security-architecture)
26. [Observability](#26-observability)
27. [Deployment Architecture](#27-deployment-architecture)
28. [Testing Strategy](#28-testing-strategy)
29. [Migration Path](#29-migration-path)

### C. Checklists

30. [Security Checklist](#30-security-checklist)
31. [Production Deployment Checklist](#31-production-deployment-checklist)
32. [Performance Checklist](#32-performance-checklist)
33. [Quick Reference](#33-quick-reference)

---

## Design goals

| Goal | Decision |
|------|----------|
| Scale | Horizontal app servers, Redis cluster, DB primary + replicas |
| Consistency of code | One request path: FormRequest → Controller → Service → Repository |
| Change safety | Depend on interfaces (DIP); extend via new classes (OCP) |
| Operations | Queues, metrics, tracing, zero-downtime deploys |
| Default topology | **Modular monolith**, not a mesh of services |

---

## 1. Architecture Overview

The original request path is unchanged. Scale is added **around** it (CDN, WAF, LB, replicas, Redis, object storage, workers), not by putting Eloquent in controllers.

```
HTTP Request
    │
    ▼
FormRequest          ← Validation + Authorization
    │
    ▼
Controller (thin)    ← Receives, calls service, returns response
    │
    ▼
ServiceInterface     ← Contract (DIP)
    │
    ▼
Service              ← Business logic, events, jobs, transactions
    │
    ▼
RepositoryInterface  ← Contract (DIP)
    │
    ▼
Repository           ← Eloquent queries + caching
    │
    ▼
MySQL/PostgreSQL  ·  Redis  ·  S3-compatible object storage
```

### SOLID mapping

| Principle | Applied at |
|---|---|
| S — Single Responsibility | Each class has exactly one job |
| O — Open/Closed | Extend via new classes, never modify existing |
| L — Liskov Substitution | Any implementation of an interface can replace another |
| I — Interface Segregation | Small, focused interfaces per module |
| D — Dependency Inversion | Inject interfaces, never concrete classes |

### What stays in the monolith

Auth, users, CMS/pages, orders, payments, notifications, and webhooks stay in **one Laravel deployable** with **module boundaries**. Shared Kernel holds DTOs, exceptions, auth contracts, and pagination. That is enough for 10M users if the **data plane** (DB, cache, queues, CDN) is scaled.

---

## 2. Folder Structure

Original module layout is preserved and wrapped in a Shared Kernel + Core infrastructure layer.

```
app/
├── Core/                              # Framework adapters (not business)
│   ├── Exceptions/
│   │   ├── Handler.php
│   │   ├── DomainException.php
│   │   └── ApiExceptionRenderer.php
│   ├── Http/
│   │   ├── Middleware/
│   │   │   ├── AuthenticateServiceClient.php
│   │   │   ├── SetLocale.php
│   │   │   ├── IdempotencyKey.php
│   │   │   └── RequestId.php
│   │   └── Resources/
│   │       └── JsonApiResource.php
│   ├── Support/
│   │   ├── CacheKeys.php
│   │   ├── Clock.php
│   │   └── Pagination.php
│   └── Observability/
│       ├── Metrics.php
│       └── Tracing.php
│
├── Shared/                            # Shared Kernel (used by all modules)
│   ├── DTOs/
│   ├── Contracts/
│   ├── Enums/
│   ├── ValueObjects/
│   ├── Policies/
│   └── Auth/
│       ├── Permissions.php
│       └── TenantContext.php
│
└── Modules/
    ├── Identity/                      # Users, roles, sessions, audit
    │   ├── Controllers/
    │   ├── Requests/
    │   ├── DTOs/
    │   ├── Services/Contracts/
    │   ├── Repositories/Contracts/
    │   ├── Models/
    │   ├── Policies/
    │   ├── Events/
    │   ├── Listeners/
    │   ├── Jobs/
    │   └── Providers/
    ├── Cms/                           # Pages, widgets, menus, forms
    ├── Orders/
    │   ├── Controllers/
    │   │   └── OrderController.php
    │   ├── Requests/
    │   │   └── CreateOrderRequest.php
    │   ├── DTOs/
    │   │   └── CreateOrderDTO.php
    │   ├── Services/
    │   │   ├── Contracts/
    │   │   │   └── OrderServiceInterface.php
    │   │   └── OrderService.php
    │   ├── Repositories/
    │   │   ├── Contracts/
    │   │   │   └── OrderRepositoryInterface.php
    │   │   └── OrderRepository.php
    │   ├── Models/
    │   │   └── Order.php
    │   ├── Events/
    │   │   └── OrderCreated.php
    │   ├── Listeners/
    │   │   ├── SendOrderConfirmationEmail.php
    │   │   └── NotifyExternalAppsViaWebhook.php
    │   ├── Notifications/
    │   │   └── OrderConfirmedNotification.php
    │   ├── Jobs/
    │   │   └── ProcessOrderPaymentJob.php
    │   ├── Policies/
    │   │   └── OrderPolicy.php
    │   └── Providers/
    │       └── OrderServiceProvider.php
    ├── Payments/
    │   ├── Gateways/
    │   │   ├── Contracts/
    │   │   │   └── PaymentGatewayInterface.php
    │   │   ├── StripeGateway.php
    │   │   └── PayPalGateway.php
    │   ├── Services/
    │   │   ├── Contracts/
    │   │   │   └── PaymentServiceInterface.php
    │   │   └── PaymentService.php
    │   └── Webhooks/
    │       └── StripeWebhookController.php
    ├── Notifications/
    │   ├── Channels/
    │   └── Templates/
    ├── Webhooks/
    │   ├── Dispatcher.php
    │   ├── SignatureVerifier.php
    │   └── Models/
    │       ├── WebhookSubscription.php
    │       └── WebhookLog.php
    └── Catalog/

routes/
├── api.php                 # version dispatcher
├── api_v1.php
└── api_v2.php              # additive, never break v1

database/
├── migrations/
├── seeders/
└── factories/

modules/{Name}/database/migrations/   # optional per-module migrations
```

**Module rule:** a module may call another module **only through that module’s Service interface**, never through its Eloquent models or repositories.

---

## 3. DTO — Data Transfer Object

```php
<?php
// app/Modules/Orders/DTOs/CreateOrderDTO.php

namespace App\Modules\Orders\DTOs;

final class CreateOrderDTO
{
    public function __construct(
        public readonly int     $userId,
        public readonly array   $items,
        public readonly string  $currency,
        public readonly ?string $couponCode = null,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            userId:     $data['user_id'],
            items:      $data['items'],
            currency:   $data['currency'] ?? 'USD',
            couponCode: $data['coupon_code'] ?? null,
        );
    }
}
```

> **Rule:** DTOs are immutable (`readonly`). No logic. Only typed data transport.
> Never pass raw `$request->all()` into a Service.

At scale, also use **response DTOs / API Resources** so Eloquent models never leak to HTTP.

---

## 4. FormRequest — Validation Layer

```php
<?php
// app/Modules/Orders/Requests/CreateOrderRequest.php

namespace App\Modules\Orders\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create-orders');
    }

    public function rules(): array
    {
        return [
            'items'              => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity'   => ['required', 'integer', 'min:1'],
            'items.*.price'      => ['required', 'numeric', 'min:0'],
            'currency'           => ['sometimes', 'string', 'in:USD,EUR,EGP'],
            'coupon_code'        => ['nullable', 'string', 'max:30'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'At least one item is required.',
        ];
    }
}
```

> **Rule:** HTTP validation lives here. Domain invariants (e.g. “cannot cancel a delivered order”) live in the Service.

---

## 5. Service Interface (DIP)

```php
<?php
// app/Modules/Orders/Services/Contracts/OrderServiceInterface.php

namespace App\Modules\Orders\Services\Contracts;

use App\Modules\Orders\DTOs\CreateOrderDTO;
use App\Modules\Orders\Models\Order;

interface OrderServiceInterface
{
    public function createOrder(CreateOrderDTO $dto): Order;
    public function cancelOrder(int $orderId, int $userId): Order;
    public function getUserOrders(int $userId, int $perPage = 15): mixed;
}
```

> **Rule (DIP):** The Controller depends on this interface, never on `OrderService` directly.
> Swap the implementation without touching any other file.

---

## 6. Service — Business Logic

```php
<?php
// app/Modules/Orders/Services/OrderService.php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\DTOs\CreateOrderDTO;
use App\Modules\Orders\Events\OrderCreated;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Repositories\Contracts\OrderRepositoryInterface;
use App\Modules\Orders\Services\Contracts\OrderServiceInterface;
use App\Modules\Payments\Services\Contracts\PaymentServiceInterface;
use Illuminate\Support\Facades\DB;

class OrderService implements OrderServiceInterface
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly PaymentServiceInterface  $paymentService,
    ) {}

    public function createOrder(CreateOrderDTO $dto): Order
    {
        return DB::transaction(function () use ($dto) {
            $total = $this->calculateTotal($dto->items, $dto->couponCode);

            $order = $this->orderRepository->create([
                'user_id'  => $dto->userId,
                'total'    => $total,
                'currency' => $dto->currency,
                'status'   => 'pending',
            ]);

            $this->orderRepository->attachItems($order->id, $dto->items);

            event(new OrderCreated($order));

            return $order;
        });
    }

    public function cancelOrder(int $orderId, int $userId): Order
    {
        $order = $this->orderRepository->findByUserOrFail($orderId, $userId);

        if (! $order->canBeCancelled()) {
            throw new \DomainException("Order #{$orderId} cannot be cancelled.");
        }

        return $this->orderRepository->updateStatus($order->id, 'cancelled');
    }

    public function getUserOrders(int $userId, int $perPage = 15): mixed
    {
        return $this->orderRepository->getByUser($userId, $perPage);
    }

    private function calculateTotal(array $items, ?string $couponCode): float
    {
        $total = collect($items)->sum(fn ($i) => $i['price'] * $i['quantity']);

        if ($couponCode) {
            $total -= $this->applyCoupon($couponCode, $total);
        }

        return round($total, 2);
    }

    private function applyCoupon(string $code, float $total): float
    {
        return 0.0;
    }
}
```

**Transaction rule:** one Service method = one unit of work. Do not open nested transactions across modules; publish an event and let the other module handle its write on a queue if it is slow or external.

---

## 7. Repository Interface (DIP)

```php
<?php
// app/Modules/Orders/Repositories/Contracts/OrderRepositoryInterface.php

namespace App\Modules\Orders\Repositories\Contracts;

use App\Modules\Orders\Models\Order;

interface OrderRepositoryInterface
{
    public function create(array $data): Order;
    public function find(int $id): ?Order;
    public function findByUserOrFail(int $id, int $userId): Order;
    public function getByUser(int $userId, int $perPage): mixed;
    public function updateStatus(int $id, string $status): Order;
    public function attachItems(int $orderId, array $items): void;
}
```

> **Rule (ISP):** Keep interfaces small. If you need reporting queries, make a
> separate `OrderReportRepositoryInterface` — do not bloat this one.

Reporting queries must hit **read replicas** (see Database Scalability).

---

## 8. Repository Implementation

```php
<?php
// app/Modules/Orders/Repositories/OrderRepository.php

namespace App\Modules\Orders\Repositories;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Repositories\Contracts\OrderRepositoryInterface;
use Illuminate\Support\Facades\Cache;

class OrderRepository implements OrderRepositoryInterface
{
    public function __construct(private readonly Order $model) {}

    public function create(array $data): Order
    {
        return $this->model->create($data);
    }

    public function find(int $id): ?Order
    {
        return Cache::remember("order:{$id}", 300, fn () =>
            $this->model->with(['items', 'user'])->find($id)
        );
    }

    public function findByUserOrFail(int $id, int $userId): Order
    {
        return $this->model
            ->where('id', $id)
            ->where('user_id', $userId)
            ->firstOrFail();
    }

    public function getByUser(int $userId, int $perPage): mixed
    {
        return $this->model
            ->where('user_id', $userId)
            ->with('items')
            ->latest()
            ->paginate($perPage);
    }

    public function updateStatus(int $id, string $status): Order
    {
        $order = $this->model->findOrFail($id);
        $order->update(['status' => $status]);

        Cache::forget("order:{$id}");

        return $order->fresh();
    }

    public function attachItems(int $orderId, array $items): void
    {
        $this->model->findOrFail($orderId)
            ->items()
            ->createMany($items);
    }
}
```

At 10M users, prefer **explicit cache keys** from `App\Core\Support\CacheKeys` and tag invalidation (see Caching Strategy). Always `with()` relations used by the caller — never lazy-load in production.

---

## 9. Controller — Thin Layer

```php
<?php
// app/Modules/Orders/Controllers/OrderController.php

namespace App\Modules\Orders\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\DTOs\CreateOrderDTO;
use App\Modules\Orders\Requests\CreateOrderRequest;
use App\Modules\Orders\Services\Contracts\OrderServiceInterface;
use Illuminate\Http\JsonResponse;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderServiceInterface $orderService
    ) {}

    public function store(CreateOrderRequest $request): JsonResponse
    {
        $dto   = CreateOrderDTO::fromRequest($request->validated() + [
            'user_id' => $request->user()->id,
        ]);
        $order = $this->orderService->createOrder($dto);

        return response()->json(['data' => $order], 201);
    }

    public function index(): JsonResponse
    {
        $orders = $this->orderService->getUserOrders(auth()->id());

        return response()->json(['data' => $orders]);
    }

    public function cancel(int $id): JsonResponse
    {
        $order = $this->orderService->cancelOrder($id, auth()->id());

        return response()->json(['data' => $order]);
    }
}
```

> **Rule (SRP):** The controller does exactly 3 things:
> receive validated input → call service → return JSON.
> No `if`, no Eloquent, no business logic.

---

## 10. Model — Relations Only

```php
<?php
// app/Modules/Orders/Models/Order.php

namespace App\Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'user_id', 'total', 'currency', 'status',
    ];

    protected $casts = [
        'total' => 'float',
    ];

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function user()
    {
        return $this->belongsTo(\App\Modules\Identity\Models\User::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }

    public function canBeCancelled(): bool
    {
        return in_array($this->status, ['pending', 'processing'], true);
    }
}
```

---

## 11. ServiceProvider — Bindings

```php
<?php
// app/Modules/Orders/Providers/OrderServiceProvider.php

namespace App\Modules\Orders\Providers;

use Illuminate\Support\ServiceProvider;
use App\Modules\Orders\Repositories\Contracts\OrderRepositoryInterface;
use App\Modules\Orders\Repositories\OrderRepository;
use App\Modules\Orders\Services\Contracts\OrderServiceInterface;
use App\Modules\Orders\Services\OrderService;

class OrderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            OrderRepositoryInterface::class,
            OrderRepository::class
        );

        $this->app->bind(
            OrderServiceInterface::class,
            OrderService::class
        );
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'orders');
    }
}
```

Register module providers in `bootstrap/providers.php` (Laravel 11+ / 13) or `config/app.php`.

---

## 12. Event System

Keep the original event/listener split. Listeners that talk to email, SMS, or third parties **must** implement `ShouldQueue`.

### Event

```php
<?php
namespace App\Modules\Orders\Events;

use App\Modules\Orders\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Order $order) {}
}
```

### Listener — Email (`notifications` queue)

```php
<?php
namespace App\Modules\Orders\Listeners;

use App\Modules\Orders\Events\OrderCreated;
use App\Modules\Orders\Notifications\OrderConfirmedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendOrderConfirmationEmail implements ShouldQueue
{
    public string $queue = 'notifications';

    public function handle(OrderCreated $event): void
    {
        $event->order->user->notify(new OrderConfirmedNotification($event->order));
    }
}
```

### Listener — Webhook (`webhooks` queue)

```php
<?php
namespace App\Modules\Orders\Listeners;

use App\Modules\Orders\Events\OrderCreated;
use App\Modules\Webhooks\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyExternalAppsViaWebhook implements ShouldQueue
{
    public string $queue = 'webhooks';

    public function __construct(private readonly Dispatcher $dispatcher) {}

    public function handle(OrderCreated $event): void
    {
        $this->dispatcher->dispatch('order.created', [
            'order_id' => $event->order->id,
            'total'    => $event->order->total,
            'status'   => $event->order->status,
        ]);
    }
}
```

---

## 13. Notification System

Unchanged from the original: multi-channel (`mail`, SMS, FCM, `database`), driven by user preference, **queued**.

Do not send mail/SMS inside the HTTP request. That path cannot survive 100K DAU.

---

## 14. Outgoing Webhooks

Preserve HMAC signing, timeouts, and retries. Deliver on the dedicated `webhooks` queue. After max tries, move to a **dead-letter** table (`webhook_dead_letters`) for ops replay — do not retry forever on the hot queue.

---

## 15. Payment Integration

Preserve `PaymentGatewayInterface` (OCP + DIP). Stripe/PayPal remain swap-in implementations bound from config. Webhook handlers verify signatures, then fire **internal** events. Never trust a query-string “paid=1”.

---

## 16. API Gateway — User and Service Auth

Original API-key client table and `AuthenticateServiceClient` middleware stay for App2/App3.

At scale, put a **real gateway** in front (see §23): rate limits, TLS, WAF, and routing to the Laravel pool. Laravel still owns **business auth** (Sanctum / policies / service clients).

```
Internet
  → CDN
  → WAF
  → Load balancer / API Gateway
  → Laravel app instances (stateless)
       ├─ auth:sanctum          (humans / SPA)
       └─ service.client:scope  (App2 / App3)
```

---

## 17. SOLID Principles Summary

### S — Single Responsibility

```
Controller  → receives request, returns response. Nothing else.
FormRequest → validates and authorizes. Nothing else.
Service     → business logic only. No HTTP, no Eloquent.
Repository  → database queries only. No business rules.
Model       → relationships, casts, scopes. No services injected.
```

### O — Open/Closed

Add `PayPalGateway implements PaymentGatewayInterface`. Bind in config. Do not edit `OrderService`.

### L — Liskov Substitution

`CachedOrderService implements OrderServiceInterface` can replace `OrderService` if it honors the same contract.

### I — Interface Segregation

Do not put `generateMonthlyReport()` on `OrderRepositoryInterface`. Use `OrderReportRepositoryInterface`.

### D — Dependency Inversion

Controllers and services depend on interfaces, never on concrete classes.

---

## 18. Infrastructure Architecture

Designed for **thousands of concurrent users** and **high API traffic**. Every Laravel node is **stateless**: no local sessions, no local file uploads, no local job storage.

### Production topology

```
                         ┌─────────────┐
                         │   Clients   │
                         │ SPA / Mobile│
                         └──────┬──────┘
                                │
                         ┌──────▼──────┐
                         │     CDN     │  static assets, cacheable GETs
                         │ CloudFront /│
                         │ Fastly / CF │
                         └──────┬──────┘
                                │
                         ┌──────▼──────┐
                         │     WAF     │  OWASP rules, bot, geo, IP
                         │ AWS WAF /   │
                         │ Cloudflare  │
                         └──────┬──────┘
                                │
                         ┌──────▼──────┐
                         │Load Balancer│  L7, health checks, TLS term
                         │ ALB / Nginx │
                         └──────┬──────┘
              ┌─────────────────┼─────────────────┐
              │                 │                 │
       ┌──────▼──────┐   ┌──────▼──────┐   ┌──────▼──────┐
       │ Laravel App │   │ Laravel App │   │ Laravel App │
       │ (Octane or  │   │  stateless  │   │  PHP-FPM)   │
       │  PHP-FPM)   │   │             │   │             │
       └──────┬──────┘   └──────┬──────┘   └──────┬──────┘
              │                 │                 │
              └────────────┬────┴────────┬────────┘
                           │             │
                    ┌──────▼──────┐ ┌────▼─────┐
                    │ Redis       │ │  Queue   │
                    │ Cluster     │ │ Workers  │
                    │ cache/lock/ │ │ critical │
                    │ session/    │ │ emails   │
                    │ rate-limit  │ │ webhooks │
                    └─────────────┘ │ reports  │
                                    │ heavy    │
                                    └────┬─────┘
                           ┌─────────────┴─────────────┐
                    ┌──────▼──────┐             ┌──────▼──────┐
                    │ DB Primary  │────repl────►│ Read replica│
                    │ (writes)    │             │ (reads)     │
                    └─────────────┘             └─────────────┘
                    ┌─────────────┐             ┌─────────────┐
                    │ Object store│             │ Monitoring  │
                    │ S3 / MinIO  │             │ Prom/Graf   │
                    │ uploads,    │             │ Sentry      │
                    │ exports     │             │ traces      │
                    └─────────────┘             └─────────────┘
```

### Component rules

| Layer | Role | Notes |
|-------|------|--------|
| CDN | Assets + optional cached public GETs | Never cache authenticated JSON |
| WAF | Block OWASP Top 10 at the edge | Rate-limit before Laravel |
| Load balancer | Spread HTTP, drain unhealthy nodes | Sticky sessions **off** |
| App instances | Autoscale on CPU / p95 latency | Shared nothing |
| Redis cluster | Cache, sessions, locks, rate limits | Not a source of truth |
| Queue workers | Separate pools per queue | Autoscale on queue depth |
| DB primary | All writes | Failover replica ready |
| Read replicas | List/report/search reads | Lag-aware |
| Object storage | Files, exports, inbound mail attachments | Signed URLs |
| Monitoring | Metrics, logs, traces, errors | See §26 |

### Stateless app contract

- `SESSION_DRIVER=redis`
- `CACHE_STORE=redis`
- `QUEUE_CONNECTION=redis` (or SQS)
- `FILESYSTEM_DISK=s3`
- No `storage/app` writes that other nodes must see
- Sanctum tokens in DB or Redis; no server-local files

---

## 19. Laravel Application Improvements

### Domain-driven module boundaries

| Module | Owns | Must not own |
|--------|------|----------------|
| Identity | Users, roles, permissions, sessions, audit | Order totals |
| Cms | Pages, widgets, menus, forms | User passwords |
| Orders | Order lifecycle | Payment PSP APIs |
| Payments | Charges, refunds, PSP webhooks | Order line items |
| Notifications | Delivery channels | When to notify (that is an event) |
| Webhooks | Outbound HTTP to partners | Order business rules |
| Catalog | Products/prices | Checkout |

Cross-module: **Service interface or domain event only**.

### Shared Kernel

`app/Shared` holds types every module may use: money VO, locale bag, permission names, tenant id, pagination DTO. If a type is used by one module only, it does **not** belong here.

### Core infrastructure

`app/Core` wraps Laravel: exception rendering, request IDs, idempotency, cache key helpers, metrics. Core has **zero** business rules.

### Exception handling

| Type | HTTP | Client sees |
|------|------|-------------|
| `ValidationException` | 422 | Field errors |
| `AuthenticationException` | 401 | Generic unauthenticated |
| `AuthorizationException` | 403 | Forbidden |
| `ModelNotFoundException` / `NotFoundHttpException` | 404 | Not found |
| `DomainException` | 422 or 409 | Stable error `code` |
| Unexpected `Throwable` | 500 | Opaque id; full stack in Sentry |

Always return a **request id**. Never leak SQL or stack traces.

```json
{
  "error": {
    "code": "ORDER_NOT_CANCELLABLE",
    "message": "Order cannot be cancelled.",
    "request_id": "01J..."
  }
}
```

### API versioning

- URL version: `/api/v1/...`, `/api/v2/...`
- v1 stays until all clients migrate
- Additive changes stay in the current version; breaking changes require v2
- Deprecate with `Sunset` header and a changelog

### Authentication

| Client | Mechanism |
|--------|-----------|
| SPA / mobile user | Laravel Sanctum personal access tokens (or cookie SPA on same-site) |
| Partner apps | Hashed API keys + scopes (existing `service_clients`) |
| Internal workers | No HTTP; in-process / queue |
| Admin break-glass | Short-lived tokens + MFA at IdP when enterprise SSO is added |

Tokens are revoked on password reset, deactivation, and explicit logout. App servers stay stateless.

### Authorization

- **Policies** for resource actions (`OrderPolicy@cancel`)
- **Permissions** for CMS/admin (`users.update`, `pages.manage`)
- FormRequest `authorize()` delegates to policies/permissions — it does not duplicate rules
- Never check `role === 'admin'` in services; use `can()` / `hasPermission()`

### Multi-tenancy readiness

Do **not** split the database on day one. Prepare the model:

1. `tenant_id` on tenant-owned tables (nullable until used)
2. Global scope `TenantScope` applied when `TenantContext` is set
3. Cache keys include `{tenant}`
4. Queue jobs serialize `tenant_id`
5. Object storage prefix `tenants/{id}/`

Start with **single tenant**. Turn the scope on when the second tenant exists. Avoid database-per-tenant until a tenant’s data or compliance truly requires isolation.

---

## 20. Database Scalability

### Indexing strategy

- Primary key: `bigint` (or UUID **only** when you need client-generated / distributed ids)
- Index every foreign key used in `WHERE` / `JOIN`
- Composite indexes match left-prefix query patterns: `(user_id, created_at)` for “my orders, newest first”
- Unique indexes for natural keys (`email`, `hr_code`)
- Partial / filtered indexes where the engine allows (e.g. “active users only”)
- Do not index low-cardinality flags alone (`is_active`); combine with a selective column

### Query optimization rules

- Select only needed columns for large lists
- No `SELECT *` on wide tables in hot paths
- `EXPLAIN` any query over 50ms in staging
- Cap `LIMIT`; never unbounded `get()` on user-facing APIs
- Counts on huge tables: approximate or cached; avoid `COUNT(*)` on every request

### Avoid N+1

- Production: `Model::preventLazyLoading()`
- Repositories always `with()` what the service/resource needs
- Detect with Laravel Telescope / Clockwork in non-prod, and `n+1` tests

### Transactions

- Wrap multi-table writes in `DB::transaction()` inside the **Service**
- Keep transactions short: no HTTP, no sleep, no queue `dispatch()->afterResponse` needed inside the lock if you can dispatch after commit
- Use `DB::afterCommit()` to fire events/jobs so listeners do not see uncommitted rows
- Retry deadlocks (MySQL 1213) a bounded number of times on idempotent writes

### Read/write splitting

```php
// config/database.php
'mysql' => [
    'read' => [
        'host' => [env('DB_READ_HOST'), env('DB_READ_HOST_2')],
    ],
    'write' => [
        'host' => [env('DB_WRITE_HOST')],
    ],
    'sticky' => true, // read-your-writes in the same request
],
```

| Traffic | Connection |
|---------|------------|
| Login, create, update, deactivate | write (primary) |
| List pages, reports, search | read replica |
| “Just created, show me the row” | sticky / primary |

### Replication

- Async replicas for read scale
- Semi-sync or managed failover (RDS Multi-AZ / Cloud SQL HA) for the primary
- Monitor replica lag; if lag > threshold, reports wait or fall back to primary
- Backups: automated snapshots + PITR

### Large tables, partitioning, archiving

| Technique | When |
|-----------|------|
| Indexes + archive | First choice; tables under tens of millions of hot rows |
| Partitioning | Time-series: audit logs, webhook logs, job payloads, analytics events |
| Sharding | Single table cannot fit one primary even after archive/partition, **and** access is shardable (e.g. `tenant_id`) |

**Archiving:** move completed orders / old audit rows older than N months to `*_archive` (same schema or cold store). App lists default to hot tables.

**Partitioning example:** `audit_logs` by `RANGE (YEAR(created_at))` or monthly. Always include the partition key in queries.

**Sharding:** last resort. Prefer a modular monolith with one primary + replicas. Shard only a specific bounded context (e.g. notifications inbox) if it dominates storage.

### PostgreSQL vs MySQL

| Choose | When |
|--------|------|
| **MySQL / MariaDB** | Existing Laravel/Laragon ops, lots of simple OLTP, team already skilled |
| **PostgreSQL** | Heavy JSON, full-text, GIS, richer partitioning, stricter SQL |

Either engine can serve 100K DAU with replicas. The bottleneck is usually **queries and indexes**, not the logo on the database.

### Read replicas — when

Add when primary CPU or IOPS is high from `SELECT`s, or reporting fights OLTP. Not needed for a small admin CMS.

### Partitioning — when

Table growth is dominated by append-only time data (logs, events). Not for `users` (use archive of inactive instead).

### Sharding — when

A **single** working set no longer fits one primary after partitioning and archiving, and you can route every query by a shard key. This is a **platform rewrite** of that context — treat it as a later evolution step, not the default.

---

## 21. Caching Strategy

### Redis usage

| Concern | Redis use |
|---------|-----------|
| Response / entity cache | `Cache::remember` |
| Sessions | `SESSION_DRIVER=redis` |
| Locks | `Cache::lock` / Redis Redlock-style via Laravel locks |
| Rate limiting | `RateLimiter` + Redis |
| Queues | Redis lists / Streams **or** SQS (queues need not share the cache cluster) |
| Pub/sub | Optional for cache bust across Octane workers |

Use a **cluster** (or managed ElastiCache/Memorystore) with eviction policy `volatile-lru` for cache DBs. Do not store irreplaceable data only in Redis.

### Key naming

```
{env}:{tenant}:{module}:{entity}:{id}:{variant}

prod:global:orders:order:1842:detail
prod:t42:identity:user:991:permissions
```

Helper:

```php
final class CacheKeys
{
    public static function order(int $id): string
    {
        return implode(':', [
            config('app.env'),
            tenant_id() ?? 'global',
            'orders',
            'order',
            $id,
            'detail',
        ]);
    }
}
```

TTL examples: user permissions 60–300s; order detail 30–300s; CMS page schema 60s with purge on admin save.

### Invalidation

- **Write-through forget:** on update/delete, `Cache::forget` the entity key and related tags
- **TTL as safety net**, not the only strategy
- CMS: purge page/menu keys when widgets change
- Never cache personalized responses at the CDN

```php
Cache::tags(['order', "user:{$userId}"])->flush(); // Redis tagged cache
```

### Distributed locking

```php
$lock = Cache::lock("lock:order:pay:{$orderId}", 10);

$lock->block(5, function () use ($orderId) {
    // only one worker captures payment
});
```

Use for: payments, webhook idempotency, “generate report once”.

### Rate limiting

```php
RateLimiter::for('api', function (Request $request) {
    return [
        Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()),
        Limit::perMinute(10)->by($request->ip())->response(fn () =>
            response()->json(['error' => ['code' => 'RATE_LIMITED']], 429)
        ),
    ];
});
```

Stricter limits on `/login`, `/forgot-password`, and service-client routes.

### Sessions

- Redis sessions, cookie `secure`, `httpOnly`, `sameSite=lax` or `strict`
- SPA token auth: no server session required; idle timeout enforced in the client + token revocation on the server
- Idle / forced logout: persist `sessions_revoked_at` (already in this codebase) so all nodes reject old tokens

---

## 22. Queue Architecture

Original listeners already use `notifications` and `webhooks`. Expand to dedicated queues so a slow report cannot block password-reset mail.

### Queues

| Queue | Examples | Workers | Timeout |
|-------|----------|---------|---------|
| `critical` | session revoke fan-out, payment capture, account deactivate side effects | Always ≥2 | 30s |
| `notifications` | in-app + push | CPU-light, scale on depth | 60s |
| `emails` | mail only | Isolated (provider outage) | 120s |
| `webhooks` | partner HTTP | I/O bound | 30s + retries |
| `reports` | CSV/PDF | Few, large memory | 15m |
| `heavy` | imports, search reindex, image variants | Separate node pool | 30m |

```bash
php artisan queue:work redis --queue=critical,emails,notifications,webhooks --sleep=1 --tries=3
php artisan queue:work redis --queue=reports,heavy --timeout=900 --tries=1
```

Horizon (Redis) or SQS + workers: **always** split processes by queue group.

### Retry

- Fast queues: `$tries = 3–5`, exponential backoff (`$backoff = [10, 60, 300]`)
- Webhooks: 5 tries, then dead letter
- Heavy jobs: `tries = 1` + explicit replay from ops UI
- `retry_after` > job timeout

### Failed jobs and dead letter

- Laravel `failed_jobs` table is the first dead-letter store
- Horizon / ops dashboard to retry
- For webhooks, also persist last HTTP status and body
- Alert when `failed_jobs` rate > N/min

### Monitoring

- Queue depth, time-in-queue, fail rate, worker count
- Page if `critical` depth > 100 for 2 minutes
- Tools: Laravel Horizon, Prometheus exporters, Datadog, AWS CloudWatch SQS metrics

### Worker scaling

| Signal | Action |
|--------|--------|
| Depth rising, p99 wait up | Add workers (HPA on K8s / ASG) |
| CPU 90% on heavy | Add **nodes**, not more workers on the same box |
| Redis CPU high | Move queues to SQS or dedicated Redis |
| Deploy | `horizon:terminate` / `queue:restart` after symlink switch |

---

## 23. API Architecture

### Gateway design

Edge gateway (Kong, AWS API Gateway, Cloudflare, or Nginx) owns TLS, IP allowlists, global rate limits, and routing `/api/v1` → Laravel target group. Laravel owns validation, authz, and responses.

### Authentication

See §16 and §19. Send `Authorization: Bearer`. Service-to-service: `X-API-Key` (hashed at rest) + scopes. Optional mTLS between gateway and app.

### Service-to-service

Inside the monolith, **do not** HTTP-call yourself. Call the other module’s service. HTTP is for **external** App2/App3 only.

If a context is later extracted, that HTTP client lives behind the **same interface** the monolith already used (Liskov).

### Rate limiting

Gateway + Laravel `throttle`. Return `429` + `Retry-After`. Separate buckets for anonymous, user, and partner keys.

### Request validation

FormRequest only. Reject unknown fields in public APIs (`$request->safe()`). Enforce max JSON body size at Nginx/gateway.

### Response standards

```json
{
  "data": { },
  "meta": { "request_id": "01J..." }
}
```

Errors: `{ "error": { "code", "message", "details?", "request_id" } }`  
Validation: Laravel 422 `errors` map, plus `request_id`.

### Pagination

Cursor pagination for large feeds (`?cursor=`). Length-aware `page` only for small admin tables.

```
GET /api/v1/orders?per_page=25&cursor=eyJpZCI6...
```

`per_page` max 100. Always return `meta.next_cursor`.

### Versioning

`/api/v1` frozen for breaking changes. Document with OpenAPI. Compatibility tests against v1 fixtures.

---

## 24. Performance Optimization

### Laravel Octane

Use Octane (Swoole/RoadRunner) when p95 is dominated by bootstrap, **after** the app is proven stateless.

Rules:

- No static mutable state leaking between requests
- Reset tenant/auth in middleware
- Restart workers on memory creep (`--max-requests`)
- Keep PHP-FPM as the fallback runtime

### PHP-FPM (when not on Octane)

- `pm = dynamic` or `ondemand` on bursty admin; `static` on steady API
- `pm.max_children` from `(RAM - OS) / avg process size`
- `request_terminate_timeout` slightly above longest allowed HTTP

### OPcache

```ini
opcache.enable=1
opcache.memory_consumption=256
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0   ; production: reset on deploy
opcache.jit=1255
opcache.jit_buffer_size=64M
```

### Laravel caches (deploy step)

```bash
php artisan optimize
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

### Lazy loading

`Model::preventLazyLoading(! app()->isProduction());` in production use **prevent** as well once queries are clean, or log violations.

### Profiling

| Env | Tool |
|-----|------|
| Local | Laravel Telescope, Clockwork, Blackfire |
| Staging | k6 + New Relic / Datadog APM |
| Prod | APM + slow query log; sample traces |

Budget: HTML/JSON API p95 < 300ms for reads, < 500ms for writes excluding PSP.

---

## 25. Security Architecture

### OWASP

Protect at WAF **and** in Laravel: injection, XSS, CSRF, broken auth, SSRF on webhooks, security misconfig, vulnerable deps (`composer audit`).

### Input validation

FormRequest + explicit types. No `unserialize` on user input. File uploads: MIME + size + store on S3, never execute.

### SQL injection

Eloquent / query builder only. No concatenated SQL. If raw SQL is required, bindings only.

### XSS

API returns JSON; SPA encodes on render. If Blade exists, `{!! !!}` is forbidden for user content.

### CSRF

Cookie SPA: Sanctum CSRF cookie. Bearer API: CSRF N/A; still CORS allowlist, no `*`.

### Encryption

- `APP_KEY` in a secret manager, not git
- `encrypted` casts for sensitive columns (national ids, tokens)
- TLS 1.2+ everywhere
- Hash passwords with Laravel hasher; hash API keys (SHA-256) before store

### Secrets management

AWS Secrets Manager / GCP Secret Manager / Vault. Inject at deploy. Rotate `APP_KEY` only with a documented decrypt/re-encrypt plan.

### Audit logging

Every privileged mutation: actor id, subject type/id, action, before/after (no passwords/tokens), IP, timestamp. This codebase already uses `audit_logs` for user create/update/deactivate — keep that pattern for orders, payments, and permission changes.

---

## 26. Observability

| Signal | What | Tools (pick a stack, do not run all) |
|--------|------|--------------------------------------|
| Errors | Unhandled exceptions, 5xx | **Sentry** |
| Metrics | RPS, latency, queue depth, replica lag | **Prometheus + Grafana**, or **Datadog**, or **New Relic** |
| Logs | JSON logs + request_id | Fluent Bit / CloudWatch / Loki |
| Traces | Gateway → Laravel → Redis → DB | OpenTelemetry → Grafana Tempo / Datadog / New Relic |
| Slow SQL | > 100ms | DB slow log + APM |
| Business | signups, logins, deactivations, orders, payment success % | Metrics + warehouse |

Log shape:

```json
{"level":"info","request_id":"01J","user_id":44,"action":"user.deactivated","subject_id":90,"duration_ms":12}
```

Alert examples: 5xx > 1% for 5m; `critical` queue age > 2m; replica lag > 10s; error budget burn; login 422 spike (credential stuffing).

---

## 27. Deployment Architecture

### Docker

- App image: PHP 8.3-FPM or Octane + Nginx sidecar, or RoadRunner
- Separate **worker** image (same code, different CMD)
- Read-only root FS where possible; secrets as env

### Container strategy

| Process | Replicas |
|---------|----------|
| `app` (HTTP) | HPA 3–N |
| `worker-critical` | min 2 |
| `worker-mail` | 1–N |
| `worker-heavy` | 0–N (scale to zero OK) |
| `scheduler` | **exactly 1** (`* * * * * php artisan schedule:run`) |

### Environments

`local` → `staging` (prod-like data subset) → `production`. Config via env, not `if (env())` in services after `config:cache`.

### CI pipeline

1. `composer install --no-dev` (or with dev for tests)
2. `php artisan test` + PHPStan/Pint
3. Frontend `npm test` / `tsc --noEmit` / lint
4. Build and scan image (Trivy)
5. Push image digest
6. Deploy staging; smoke `/up` and `/api/guest`
7. Deploy production

### Zero downtime

- Rolling update: LB health `/up` must fail until app is ready
- `php artisan migrate --force` **before** or **expand/contract**: additive migrations first, then code, then drop columns later
- `queue:restart` / Horizon terminate so workers load new code
- Octane: rolling reload workers

### Rollback

- Keep previous image digest
- Rollback app **without** reversing destructive migrations
- DB rollback only for forward-fix migrations that are additive; otherwise fix-forward

---

## 28. Testing Strategy

Original unit (mocked repository) and feature (HTTP) tests stay. Expand as follows.

### Unit

Services with mocked `*Interface` repositories. No HTTP, no DB. Example: original `OrderServiceTest`.

### Feature

Full HTTP + `RefreshDatabase` (or `DatabaseTransactions`). Example: original `CreateOrderTest`. This repo already uses this style (`AdminDeactivatesUsersTest`, etc.).

### Integration

One test hitting Redis/queue fake + DB: “create order dispatches `emails` job”. `Queue::fake()` + `Bus::assertDispatched`.

### API

Contract tests: status codes, JSON schema, pagination keys, 401/403/422. Version v1 fixtures must not break.

### Load

k6/Gatling against staging:

- mixed reads (bootstrap, page, widget data)
- login + write burst
- target: p95 budgets from §24

### Stress

Raise VUs until saturation; record the breaking resource (DB CPU vs Redis vs workers). That graph drives replica/worker counts.

### Security

- OWASP ZAP baseline on staging
- Tests: unauthenticated 401, viewer 403 on deactivate, inactive login message, webhook bad signature 401
- Dependency scanning in CI

### Load test sketch (k6)

```javascript
import http from 'k6/http'
import { check } from 'k6'

export const options = { vus: 200, duration: '5m' }

export default function () {
  const res = http.get(`${__ENV.BASE}/api/guest`)
  check(res, { 'guest 200': (r) => r.status === 200 })
}
```

---

## 29. Migration Path

**Do not start with microservices.** Extract a service only when a module has an independent scale, team, or compliance boundary **and** the interface already exists.

```
Small Laravel application
        │  thin controllers, a few services, one database
        ▼
Modular Monolith
        │  modules + Shared Kernel + queues + Redis + replicas
        ▼
High-scale Laravel platform
        │  Octane/FPM pool, Horizon, CDN, WAF, observability, archive/partition
        ▼
Microservices when required
           only the hot or isolated context, behind the same interface
```

| Stage | You have | You add |
|-------|----------|---------|
| Small app | This repo today: Laravel API + SPA, SQLite/MySQL, Sanctum | Tests, audit, FormRequest discipline |
| Modular monolith | `app/Modules/*`, providers, events | Redis sessions/cache, dedicated queues |
| High-scale platform | Many nodes | Replicas, CDN, WAF, Octane, partitioning of logs |
| Microservice | e.g. Notifications at extreme volume | Same `NotificationServiceInterface`, new deployable |

**Extract checklist:** module already isolated; async events not sync DB joins; dedicated data store identified; on-call team exists; rollback plan exists.

---

## 30. Security Checklist

- [ ] WAF + TLS 1.2+ at the edge
- [ ] Secrets only in a manager; `.env` not in git
- [ ] `APP_DEBUG=false` in production
- [ ] FormRequest on every mutating endpoint
- [ ] Eloquent/query builder only (no string-built SQL)
- [ ] Policies/permissions on every resource action
- [ ] Password reset and deactivation revoke tokens
- [ ] API keys hashed; webhook signatures verified
- [ ] Rate limits on login, reset, public APIs
- [ ] CORS allowlist; no `Access-Control-Allow-Origin: *` with credentials
- [ ] Security headers (HSTS, `X-Content-Type-Options`, `Referrer-Policy`)
- [ ] File uploads to S3; malware size/MIME caps
- [ ] Audit log without secrets
- [ ] `composer audit` / npm audit in CI
- [ ] Dependency and image scanning
- [ ] Least-privilege IAM for app → S3/Redis/DB

---

## 31. Production Deployment Checklist

- [ ] Multi-AZ load balancer + ≥2 app tasks
- [ ] Health check `/up` (and optional `/ready` for DB/Redis)
- [ ] Redis cluster for cache/session/lock
- [ ] Queue workers per queue group; scheduler singleton
- [ ] DB primary + at least one replica; backups + PITR tested
- [ ] Object storage for uploads
- [ ] `php artisan migrate --force` (expand/contract)
- [ ] `config/route/view/event:cache` and OPcache
- [ ] Horizon or equivalent running
- [ ] Sentry + metrics + log drain
- [ ] CDN for SPA assets
- [ ] Zero-downtime roll; previous image kept
- [ ] Runbook: rollback, replica lag, Redis failover, queue flood

---

## 32. Performance Checklist

- [ ] `preventLazyLoading` and `with()` on list endpoints
- [ ] Indexes for hot `WHERE`/`ORDER BY`
- [ ] Cache CMS schemas and permission bags
- [ ] Paginate everything; cursor for large feeds
- [ ] Writes on primary; heavy reads on replica
- [ ] Octane only after stateless audit
- [ ] OPcache + Laravel optimize on deploy
- [ ] Separate `heavy`/`reports` workers
- [ ] CDN for hashed JS/CSS
- [ ] p95 budgets monitored; slow query log on
- [ ] N+1 and N+query tests in CI for list APIs
- [ ] Load test staging before large campaigns

---

## 33. Quick Reference

| Question | Answer |
|---|---|
| Where does validation live? | `FormRequest` |
| Where does authorization live? | Policy / permission, called from `FormRequest::authorize()` |
| Where does business logic live? | `Service` |
| Where do DB queries live? | `Repository` |
| Where do relationships live? | `Model` |
| Where do events get fired? | `Service` (after commit) |
| Where do emails/SMS get sent? | `Listener` on `emails` / `notifications` queues |
| Where do webhook deliveries happen? | `Job` on `webhooks` queue, with retry + DLQ |
| Where do interface bindings happen? | Module `ServiceProvider` |
| What does the Controller do? | Receive → call service → respond |
| Default topology? | Modular monolith + scaled data plane |
| When microservices? | After module isolation **and** a proven scale/team reason |

---

*Foundation: original Laravel SOLID + Repository + Service + DTO modular architecture (Orders, Payments, Webhooks, service-client auth).*  
*Enhancement: production infrastructure, DDD module boundaries, database/cache/queue/API scale, security, observability, CI/CD, and an explicit path that does **not** default to microservices.*
