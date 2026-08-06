# Automated Entity Workflow & Lifecycle Management Design

**Date**: 2026-08-06  
**Status**: Proposed  

## 1. Overview
Currently, in `fine_backend`, domain models backed by an `Entity` (such as `Employee`, `Client`, `ExternalEmployer`, `Vendor`, and `User`) require an existing `entity_id` to be explicitly provided upon creation.

This feature introduces a **Universal Automated Entity Lifecycle System** that:
1. **Auto-creates underlying Entities**: When creating any Entity-backed model, if an `entity_id` is not provided, an `Entity` (Individual or Organization) and its corresponding `EntityRole` are automatically created behind the scenes.
2. **Reuses Entities for Multiple Roles**: Allows explicitly passing an existing `entity_id` to assign an additional role to an existing Entity (e.g. an Entity acting as both a Client and a Vendor).
3. **Splits / Detaches Entities**: Provides universal mechanisms to split a domain record off into a new standalone Entity or re-link it to a different Entity at any point in its lifecycle.

---

## 2. Architecture & Components

### 2.1 Centralized `EntityService`
Location: `app/Services/EntityService.php`

The `EntityService` handles all Entity provisioning and lifecycle operations:
- `createEntityForDomainModel(string $roleType, array $attributes, EntityType $entityType)`:
  - Creates the `Entity` (`name`, `entity_type`, `tax_number`, etc.).
  - Creates the `EntityRole` (`entity_id`, `role_type`, `operating_unit_id`).
  - Returns the newly created `Entity`.
- `ensureEntityRole(Entity $entity, EntityRoleType $roleType, ?string $operatingUnitId = null)`:
  - Checks if `EntityRole` exists; creates it if missing (`firstOrCreate`).
- `splitEntity(Model $domainModel, ?string $newEntityName = null)`:
  - Creates a new `Entity` record copying relevant basic details.
  - Re-assigns the specific `EntityRole` to the new Entity.
  - Updates `$domainModel->entity_id` to point to the new Entity.
- `relinkEntity(Model $domainModel, string $targetEntityId)`:
  - Re-links `$domainModel` to `$targetEntityId`.
  - Ensures `$targetEntityId` has the required `EntityRole`.

---

### 2.2 Reusable `HasEntityLifecycle` Trait
Location: `app/Models/Traits/HasEntityLifecycle.php`

Applied to models that belong to an Entity (`Employee`, `Client`, `ExternalEmployer`, etc.):
- Provides helper methods:
  - `splitEntity(?string $newName = null)`
  - `relinkEntity(string $targetEntityId)`
- Hooks into Eloquent model events or controller workflows cleanly.

---

### 2.3 Store Request Validation Updates
Form requests (`StoreEmployeeRequest`, `StoreClientRequest`, `StoreExternalEmployerRequest`, etc.):
- Make `entity_id` **nullable**.
- Add validation rules for inline Entity creation attributes when `entity_id` is null:
  - `name`: string (or `first_name` + `last_name` mapped to Entity `name`).
  - `entity_type`: enum `individual` or `organization` (defaults based on model type, e.g. `individual` for Employee, `organization` for Client/ExternalEmployer if omitted).
  - `tax_number`: optional string.

---

### 2.4 API Controllers & Routes

#### Controllers Updated:
- `EmployeeController`
- `ClientController`
- `ExternalEmployerController`
- Any future Entity-backed controllers (e.g. `VendorController` when implemented).

#### Controller `store` Logic Pattern:
```php
public function store(StoreEmployeeRequest $request, EntityService $entityService): JsonResponse
{
    $employee = DB::transaction(function () use ($request, $entityService) {
        $entityId = $request->entity_id;

        if (! $entityId) {
            $entity = $entityService->createEntityForDomainModel(
                roleType: EntityRoleType::Employee,
                attributes: [
                    'name' => $request->input('name', $request->first_name . ' ' . $request->last_name),
                    'entity_type' => EntityType::Individual,
                    'tax_number' => $request->tax_number,
                ],
                operatingUnitId: $request->operating_unit_id
            );
            $entityId = $entity->id;
        } else {
            $entity = Entity::findOrFail($entityId);
            $entityService->ensureEntityRole($entity, EntityRoleType::Employee, $request->operating_unit_id);
        }

        return Employee::create(array_merge(
            $request->validated(),
            ['entity_id' => $entityId]
        ));
    });
}
```

#### Splitting Endpoints (Universal Routes):
- `POST /api/v1/employees/{employee}/split-entity`
- `POST /api/v1/clients/{client}/split-entity`
- `POST /api/v1/external-employers/{externalEmployer}/split-entity`

---

## 3. Data Flow & Transaction Safety

1. **Atomic Creation**: All Entity + EntityRole + DomainModel creations occur within DB transactions (`DB::transaction`).
2. **Optimistic Locking & Auditing**: Existing `Auditable` and `HasOptimisticLocking` traits on `Entity` and domain models continue to enforce version integrity.
3. **Soft Delete & Separation**: Detaching/splitting updates references safely without destroying audit logs.

---

## 4. Testing Strategy (Pest PHP)

- **Unit Tests**:
  - `EntityServiceTest`: Test Entity auto-creation, `ensureEntityRole`, and `splitEntity`.
- **Feature Tests**:
  - `EmployeeControllerTest`: Verify creating an employee without `entity_id` auto-creates an Entity + EntityRole.
  - `ClientControllerTest`: Verify creating a client auto-creates an Organization Entity.
  - `SplitEntityTest`: Verify splitting an employee/client creates a separate Entity and updates `entity_id`.

---

## 5. Verification Checkpoints

- `./vendor/bin/pint --format agent` (PSR-12 style check)
- `php artisan test --compact` (All tests pass)
