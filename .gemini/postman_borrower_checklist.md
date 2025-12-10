# Borrower Documents Checklist - Postman Testing Guide

## Base URL
```
http://localhost/api/v1/common
```

---

## 🔐 Authentication
All requests require authentication. Add to Headers:
```
Authorization: Bearer YOUR_ACCESS_TOKEN
Accept: application/json
```

---

## 📋 API 1: GET Document Checklist

### Endpoint
```
GET {{base_url}}/borrower-documents-checklist/{borrowerId}
```

### Example Request
```
GET http://localhost/api/v1/common/borrower-documents-checklist/1
```

### Success Response (200 OK)
Shows ALL document types with checked/unchecked status:
```json
{
  "success": true,
  "message": "Document checklist retrieved successfully.",
  "data": [
    {
      "id": 1,
      "name": "ID Proof",
      "sort_order": 1,
      "current_status": true    // ✅ CHECKED - exists in borrower_documents_checklists
    },
    {
      "id": 2,
      "name": "Address Proof",
      "sort_order": 2,
      "current_status": false   // ❌ UNCHECKED - does NOT exist in checklist
    },
    {
      "id": 3,
      "name": "Bank Statement",
      "sort_order": 3,
      "current_status": true    // ✅ CHECKED
    },
    {
      "id": 4,
      "name": "Income Proof",
      "sort_order": 4,
      "current_status": false   // ❌ UNCHECKED
    }
  ]
}
```

### Error Response - Borrower Not Found (404)
```json
{
  "success": false,
  "message": "Borrower not found."
}
```

### Error Response - Server Error (500)
```json
{
  "success": false,
  "message": "Failed to retrieve document checklist.",
  "error": "Error details here"
}
```

---

## 💾 API 2: SAVE Document Checklist

### Endpoint
```
POST {{base_url}}/borrower-documents-checklist/save
```

### Example Request
```
POST http://localhost/api/v1/common/borrower-documents-checklist/save
Content-Type: application/json
```

### Request Body
```json
{
  "borrower_id": 1,
  "partner_id": 5,
  "checked_doc_type_ids": [1, 3, 5]
}
```

**Note:** 
- Only send IDs of documents that are **CHECKED**
- Unchecked documents should NOT be in this array
- The system will delete all existing items and create new ones for checked items only

### Success Response (200 OK)
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
      "funding_application_id": null,
      "created_at": "2025-12-10T12:00:00.000000Z",
      "updated_at": "2025-12-10T12:00:00.000000Z",
      "deleted_at": null
    },
    {
      "id": 2,
      "borrower_id": 1,
      "partner_id": 5,
      "borrower_doc_types_id": 3,
      "document_name": "Bank Statement",
      "current_status": true,
      "funding_application_id": null,
      "created_at": "2025-12-10T12:00:00.000000Z",
      "updated_at": "2025-12-10T12:00:00.000000Z",
      "deleted_at": null
    },
    {
      "id": 3,
      "borrower_id": 1,
      "partner_id": 5,
      "borrower_doc_types_id": 5,
      "document_name": "Tax Returns",
      "current_status": true,
      "funding_application_id": null,
      "created_at": "2025-12-10T12:00:00.000000Z",
      "updated_at": "2025-12-10T12:00:00.000000Z",
      "deleted_at": null
    }
  ]
}
```

### Validation Error Response (422)
```json
{
  "message": "The borrower id field is required. (and 1 more error)",
  "errors": {
    "borrower_id": [
      "The borrower id field is required."
    ],
    "partner_id": [
      "The selected partner id is invalid."
    ]
  }
}
```

### Error Response - Borrower Not Found (422)
```json
{
  "message": "The selected borrower id is invalid.",
  "errors": {
    "borrower_id": [
      "The selected borrower id is invalid."
    ]
  }
}
```

---

## 🧪 Test Scenarios

### Scenario 1: Get Checklist for Valid Borrower
```
GET /borrower-documents-checklist/1

Expected: 200 OK with all doc types showing current_status true/false
```

### Scenario 2: Get Checklist for Invalid Borrower
```
GET /borrower-documents-checklist/99999

Expected: 404 with message "Borrower not found."
```

### Scenario 3: Save Checklist - Check Some Documents
```
POST /borrower-documents-checklist/save
Body:
{
  "borrower_id": 1,
  "partner_id": 5,
  "checked_doc_type_ids": [1, 3]
}

Expected: 200 OK with 2 created items
Then GET the checklist to verify:
- Doc ID 1: current_status = true
- Doc ID 2: current_status = false
- Doc ID 3: current_status = true
```

### Scenario 4: Save Checklist - Uncheck All
```
POST /borrower-documents-checklist/save
Body:
{
  "borrower_id": 1,
  "partner_id": 5,
  "checked_doc_type_ids": []
}

Expected: 200 OK with empty data array
Then GET the checklist to verify all have current_status = false
```

### Scenario 5: Save with Invalid Borrower ID
```
POST /borrower-documents-checklist/save
Body:
{
  "borrower_id": 99999,
  "partner_id": 5,
  "checked_doc_type_ids": [1]
}

Expected: 422 Validation Error "The selected borrower id is invalid."
```

### Scenario 6: Save with Invalid Doc Type ID
```
POST /borrower-documents-checklist/save
Body:
{
  "borrower_id": 1,
  "partner_id": 5,
  "checked_doc_type_ids": [99999]
}

Expected: 422 Validation Error "The selected checked doc type ids.0 is invalid."
```

---

## 📊 Understanding current_status

### In GET Response:
- `current_status: true` = Document IS checked (exists in borrower_documents_checklists table)
- `current_status: false` = Document is NOT checked (does NOT exist in checklist table)

### In Database:
- Only CHECKED documents are stored in `borrower_documents_checklists`
- All stored records have `current_status = true`
- Unchecked documents have NO entry in the table

---

## 🔄 Workflow Example

1. **Initial State** - No checklist exists for borrower
   ```
   GET /borrower-documents-checklist/1
   Result: All items have current_status: false
   ```

2. **User Checks 3 Documents**
   ```
   POST /borrower-documents-checklist/save
   {
     "borrower_id": 1,
     "partner_id": 5,
     "checked_doc_type_ids": [1, 2, 3]
   }
   ```

3. **Verify Checklist**
   ```
   GET /borrower-documents-checklist/1
   Result: 
   - Doc 1, 2, 3: current_status: true
   - All others: current_status: false
   ```

4. **User Unchecks Doc 2**
   ```
   POST /borrower-documents-checklist/save
   {
     "borrower_id": 1,
     "partner_id": 5,
     "checked_doc_type_ids": [1, 3]
   }
   ```

5. **Verify Update**
   ```
   GET /borrower-documents-checklist/1
   Result: 
   - Doc 1, 3: current_status: true
   - Doc 2 and all others: current_status: false
   ```

---

## 📝 Postman Collection Variables

Set up these variables in Postman:

```
base_url: http://localhost/api/v1/common
token: your_bearer_token_here
borrower_id: 1
partner_id: 5
```

---

## ✅ Validation Rules

### GET Checklist:
- `borrower_id` must be a valid integer (checked in controller)
- Returns 404 if borrower not found

### SAVE Checklist:
- `borrower_id`: required | integer | exists:borrowers,id
- `partner_id`: required | integer | exists:partners,id
- `checked_doc_type_ids`: required | array
- `checked_doc_type_ids.*`: integer | exists:borrower_doc_types,id
