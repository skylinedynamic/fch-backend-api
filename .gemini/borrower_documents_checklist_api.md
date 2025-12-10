# Borrower Documents Checklist API

## Overview
This API manages the borrower documents checklist, showing all document types with their checked/unchecked status.

---

## API Endpoints

### 1. **GET** - Get Document Checklist with Status

**Endpoint:**
```
GET /api/v1/common/borrower-documents-checklist/{borrowerId}
```

**Description:**
- Gets all document types from `borrower_doc_types` table
- Shows `current_status` as `true` if the document type exists in `borrower_documents_checklists` for the borrower
- Shows `current_status` as `false` if the document type does NOT exist in the checklist

**Response Example:**
```json
{
  "success": true,
  "message": "Document checklist retrieved successfully.",
  "data": [
    {
      "id": 1,
      "name": "ID Proof",
      "sort_order": 1,
      "current_status": true
    },
    {
      "id": 2,
      "name": "Address Proof",
      "sort_order": 2,
      "current_status": false
    },
    {
      "id": 3,
      "name": "Bank Statement",
      "sort_order": 3,
      "current_status": true
    }
  ]
}
```

---

### 2. **POST** - Save Document Checklist

**Endpoint:**
```
POST /api/v1/common/borrower-documents-checklist/save
```

**Description:**
- Saves/updates the entire checklist for a borrower
- Deletes all existing checklist items for the borrower
- Creates new checklist items ONLY for the checked document types
- All checked items will have `current_status = true`

**Request Body:**
```json
{
  "borrower_id": 1,
  "partner_id": 5,
  "checked_doc_type_ids": [1, 3, 5]
}
```

**Validation Rules:**
- `borrower_id`: required, integer, must exist in borrowers table
- `partner_id`: required, integer, must exist in partners table
- `checked_doc_type_ids`: required, array of integers, each must exist in borrower_doc_types table

**Response Example:**
```json
{
  "success": true,
  "message": "Document checklist saved successfully.",
  "data": [
    {
      "id": 1,
      "borrower_id": 1,
      "partner_id": 5,
      "borrower_doc_types_id": 1,
      "document_name": "ID Proof",
      "current_status": true,
      "created_at": "2025-12-10T12:00:00.000000Z",
      "updated_at": "2025-12-10T12:00:00.000000Z"
    },
    {
      "id": 2,
      "borrower_id": 1,
      "partner_id": 5,
      "borrower_doc_types_id": 3,
      "document_name": "Bank Statement",
      "current_status": true,
      "created_at": "2025-12-10T12:00:00.000000Z",
      "updated_at": "2025-12-10T12:00:00.000000Z"
    }
  ]
}
```

---

## Database Schema

### `borrower_documents_checklists` Table
```
- id (primary key)
- borrower_id (foreign key -> borrowers)
- partner_id (foreign key -> partners)
- funding_application_id (nullable)
- borrower_doc_types_id (nullable, foreign key -> borrower_doc_types)
- document_name
- current_status (boolean, default: true)
- deleted_at (soft delete)
- timestamps
```

---

## How It Works

### GET Checklist Logic:
1. Fetch all document types from `borrower_doc_types` ordered by `sort_order`
2. Fetch all checklist items for the borrower where `current_status = true`
3. For each document type:
   - If it exists in the borrower's checklist → `current_status: true`
   - If it does NOT exist → `current_status: false`

### SAVE Checklist Logic:
1. Validate request data
2. Start database transaction
3. Delete ALL existing checklist items for the borrower
4. Loop through `checked_doc_type_ids`:
   - Create new checklist item with `current_status = true`
   - Set `document_name` from the doc type
5. Commit transaction
6. Return created items

---

## Files Created/Modified

### Created:
- `app/Models/Borrower/BorrowerDocumentsChecklist.php`
- `app/Services/FCH/Common/Borrower/BorrowerDocumentsChecklistService.php`
- `app/Http/Controllers/Api/V1/FCH/Common/Borrower/BorrowerDocumentsChecklistController.php`
- `app/Http/Resources/Api/V1/FCH/Common/Borrower/BorrowerDocTypeResource.php`
- `database/migrations/2025_12_10_000001_create_borrower_documents_checklists_table.php`

### Modified:
- `app/Models/Borrower/BorrowerDocType.php` (added traits and casts)
- `routes/common.php` (added routes)
