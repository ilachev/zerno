-- name: ListFirstPage :many
SELECT id, title, description, published_at, storage_key, original_name, file_size
FROM catalog_documents
WHERE status = :status
ORDER BY id DESC
LIMIT 21;

-- name: ListBefore :many
SELECT id, title, description, published_at, storage_key, original_name, file_size
FROM catalog_documents
WHERE status = :status AND id < :before
ORDER BY id DESC
LIMIT 21;

-- name: ListBounded :many
SELECT id, title, description, published_at, storage_key, original_name, file_size
FROM catalog_documents
WHERE status = :status AND id < :before
ORDER BY id DESC
LIMIT :limit;

-- name: FindFirst :one
SELECT id, title, description, published_at, storage_key, original_name, file_size
FROM catalog_documents
WHERE status = :status
ORDER BY id DESC
LIMIT 1;

-- name: FindUnbounded :one
SELECT id, title, description, published_at, storage_key, original_name, file_size
FROM catalog_documents
WHERE status = :status
ORDER BY id DESC;

-- name: ListUnbounded :many
SELECT id, title, description, published_at, storage_key, original_name, file_size
FROM catalog_documents
WHERE status = :status AND id < :before
ORDER BY id DESC;

-- name: ListNarrowBound :many
SELECT narrow
FROM limit_bounds
WHERE narrow < :limit
ORDER BY narrow DESC
LIMIT :limit;

-- name: ListNarrowFirst :many
SELECT narrow
FROM limit_bounds
WHERE narrow < :limit AND wide < :limit
ORDER BY narrow DESC
LIMIT :limit;

-- name: ListWideFirst :many
SELECT narrow
FROM limit_bounds
WHERE wide < :limit AND narrow < :limit
ORDER BY narrow DESC
LIMIT :limit;
