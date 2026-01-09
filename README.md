# Laravel Bulk Onboarding API

A Laravel-based bulk organization onboarding system that processes up to 1000 organizations per request using background queues.

## Quick Start

```bash
# Install dependencies
composer install

# Setup environment
cp .env.example .env
php artisan key:generate

# Configure database and Redis in .env
DB_CONNECTION=mysql
QUEUE_CONNECTION=redis
CACHE_DRIVER=redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# Run migrations
php artisan migrate

# Start queue worker
php artisan queue:work --queue=onboarding
```

### Docker Setup

```bash
docker-compose up -d
docker compose exec app composer install
docker compose exec app php artisan migrate
```

Docker Compose includes Redis service for queue and cache. Ensure Redis is running before starting queue workers.

## API Usage

**POST** `/api/bulk-onboard`

```json
{
    "organizations": [
        {
            "name": "Acme Corporation",
            "domain": "acme.com",
            "contact_email": "contact@acme.com"
        }
    ]
}
```

**Response (202):**

```json
{
    "batch_id": "550e8400-e29b-41d4-a716-446655440000",
    "message": "Bulk onboarding initiated successfully",
    "organizations_count": 1
}
```

## Architecture

-   **Request Handler**: Validates and inserts organizations in chunks (500 per chunk)
-   **Background Jobs**: One job per organization for parallel processing via Redis queue
-   **Caching**: Redis cache for batch status and organization lookups
-   **Idempotency**: Status-based checks prevent duplicate processing
-   **Retry Logic**: 3 attempts with 10-second exponential backoff
-   **Duplicate Handling**: Upsert strategy (updates existing records by domain)

## Testing

```bash
composer test
```

## Assumptions

1. **Chunk Size**: 500 records per chunk balances memory usage and database performance
2. **Queue Driver**: Redis queue for better performance and scalability
3. **Cache Driver**: Redis cache for fast batch status and organization lookups
4. **Idempotency**: Status transitions (`pending` → `processing` → `completed`) are atomic
5. **Duplicate Strategy**: Upsert updates existing records rather than skipping
6. **Retry Configuration**: 3 attempts with 10-second backoff suitable for external API calls
7. **Performance**: Designed for ~10 requests/second sustained throughput

## Trade-offs

### 1. Queue Infrastructure: Redis vs Database vs SQS

-   **Chosen**: Redis queue
-   **Trade-off**: Requires Redis infrastructure but provides better performance, scalability, and lower database load
-   **Alternative**: Database queue (simpler setup but lower throughput) or AWS SQS (managed service but vendor lock-in)

### 2. Upsert vs Skip Duplicates

-   **Chosen**: Upsert (update existing records)
-   **Trade-off**: May overwrite existing data
-   **Alternative**: Skip duplicates to preserve original data (requires more complex logic)

### 3. Synchronous Validation

-   **Chosen**: Validate all organizations before processing
-   **Trade-off**: One invalid organization fails entire batch
-   **Alternative**: Validate in jobs for partial success (requires complex error handling)

### 4. Chunk Size (500 records)

-   **Chosen**: 500 records per chunk
-   **Trade-off**: Larger chunks = fewer queries but more memory; smaller chunks = more queries but less memory

### 5. One Job Per Organization

-   **Chosen**: Individual jobs for fine-grained control
-   **Trade-off**: More job overhead but better parallelization and error isolation
-   **Alternative**: Batch jobs (e.g., 100 organizations per job) for fewer jobs but less granularity

## Production-Grade Improvements

### 1. Queue Infrastructure

-   **Status**: ✅ **Implemented** - Using Redis queue
-   **Future Enhancement**: Consider AWS SQS for managed service and better durability
-   **Monitoring**: Add queue depth monitoring and alerting

### 2. Rate Limiting

-   **Implement rate limiting middleware** (e.g., 10 requests/second per IP)
-   **Benefit**: Prevents abuse and ensures fair resource usage
-   **Implementation**: Laravel rate limiter or API gateway

### 3. Monitoring & Observability

-   **Integrate APM tools** (Laravel Telescope, Sentry, DataDog)
-   **Benefit**: Better visibility into performance and errors
-   **Metrics**: Request rate, job processing time, failure rates, queue depth

### 4. Batch Status Tracking

-   **Create `batches` table** to track overall batch progress
-   **Benefit**: Users can query batch status and completion percentage
-   **Fields**: `total`, `pending`, `completed`, `failed` counts per batch

### 5. Dead Letter Queue

-   **Implement DLQ** for permanently failed jobs
-   **Benefit**: Easier debugging and manual retry of failed organizations
-   **Implementation**: Use Laravel's failed jobs table with custom retry mechanism

### 6. Database Optimization

-   **Add composite indexes** for common query patterns (`batch_id`, `status`, `created_at`)
-   **Connection pooling** for high-concurrency scenarios
-   **Read replicas** for status queries to reduce primary database load

### 7. API Enhancements

-   **API versioning** (`/api/v1/bulk-onboard`) for breaking changes
-   **Idempotency keys** in request to prevent duplicate processing on retries
-   **Domain validation** (DNS lookup, format validation) before job processing

### 8. Error Handling

-   **Structured error responses** with error codes and detailed messages
-   **Partial success support** - return success/failure per organization
-   **Webhook notifications** when batch completes or fails

### 9. Caching Strategy

-   **Status**: ✅ **Implemented** - Using Redis cache
-   **Current Usage**: Cache batch status and organization lookups
-   **Benefit**: Reduce database load for frequently accessed data
-   **TTL**: Short-lived cache (30-60 seconds) for real-time updates
-   **Future Enhancement**: Implement cache warming and cache invalidation strategies

### 10. Event-Driven Architecture

-   **Emit events** for organization status changes
-   **Benefit**: Decouple components, enable webhooks, audit logging
-   **Events**: `OrganizationCreated`, `OrganizationProcessed`, `OrganizationFailed`, `BatchCompleted`

### 11. Horizontal Scaling

-   **Status**: ✅ **Ready** - Redis queue supports multiple workers
-   **Design for multiple queue workers** across servers
-   **Benefit**: Handle higher throughput, better fault tolerance
-   **Requirements**: Shared Redis instance and load balancer

### 12. Security Enhancements

-   **API authentication** (API keys, OAuth, JWT)
-   **Input sanitization** and XSS protection
-   **SQL injection protection** (already handled by Eloquent)
-   **Request signing** for webhook endpoints

### 13. Performance Optimizations

-   **Database transactions** for chunk inserts (atomic operations)
-   **Bulk job dispatching** instead of individual dispatches
-   **Connection reuse** and query optimization
-   **Async processing** for non-critical operations (email notifications, logging)

### 14. Data Integrity

-   **Soft deletes** for audit trail
-   **Timestamps** for all status changes
-   **Version tracking** for organization updates
-   **Data validation** at multiple layers (API, Job, Database)

### 15. Operational Excellence

-   **Health check endpoints** (`/health`, `/ready`)
-   **Metrics endpoint** for monitoring systems
-   **Graceful shutdown** for queue workers
-   **Automated backups** and disaster recovery plan

## License

MIT License
