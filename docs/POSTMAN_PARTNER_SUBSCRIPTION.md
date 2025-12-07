# Partner Subscription API - Postman Request

## 📋 Overview
This document provides detailed information for testing the Partner Subscription API endpoints using Postman.

---

## 🔑 Authentication
All requests require Bearer Token authentication.

**Headers:**
```
Authorization: Bearer {your_access_token}
Accept: application/json
Content-Type: application/json
```

---

## 📌 Endpoints

### 1. Create/Update Partner Subscription

**Method:** `POST`  
**Endpoint:** `{{base_url}}/api/v1/admin/partners/subscription`

**Description:** Creates a new partner subscription or updates an existing one based on the `partner_id`.

#### Request Body (JSON)

**Example 1: Standard Subscription**
```json
{
    "partner_id": 1,
    "partner_type": "Founding Partner",
    "enrollment_type": "Standard Enrollment",
    "monthly_platform_fee": 597.00,
    "first_reg_amount": 5970.00,
    "first_reg_date": "2025-12-01",
    "installment_type": 10.00,
    "first_reg_payment": 597.00,
    "first_reg_payment_2": 0.00,
    "total_paid_amount": 597.00,
    "subscription_start_date": "2025-12-01",
    "subscription_end_date": "2026-09-30",
    "expiry_date": "2026-09-30",
    "termination_date": null,
    "status": "active"
}
```

**Example 2: Minimal Subscription**
```json
{
    "partner_id": 2,
    "partner_type": "Referral",
    "enrollment_type": null,
    "monthly_platform_fee": null,
    "first_reg_amount": null,
    "first_reg_date": null,
    "installment_type": null,
    "first_reg_payment": null,
    "first_reg_payment_2": null,
    "total_paid_amount": null,
    "subscription_start_date": null,
    "subscription_end_date": null,
    "expiry_date": null,
    "termination_date": null,
    "status": "active"
}
```

#### Field Descriptions

| Field | Type | Required | Description | Validation Rules |
|-------|------|----------|-------------|------------------|
| `partner_id` | integer | **Yes** | Partner's unique ID | Must exist in partners table |
| `partner_type` | string | No | Partner type | Max 255 chars |
| `enrollment_type` | string | No | Enrollment type | Max 255 chars |
| `monthly_platform_fee` | decimal | No | Monthly platform fee | Min: 0 |
| `first_reg_amount` | decimal | No | First registration amount | Min: 0 |
| `first_reg_date` | date | No | First registration date | Valid date format (Y-m-d) |
| `installment_type` | decimal | No | Installment type/amount | Min: 0 |
| `first_reg_payment` | decimal | No | First registration payment | Min: 0 |
| `first_reg_payment_2` | decimal | No | Second registration payment | Min: 0 |
| `total_paid_amount` | decimal | No | Total paid amount | Min: 0 |
| `subscription_start_date` | date | No | Start date of subscription | Valid date format (Y-m-d) |
| `subscription_end_date` | date | No | End date of subscription | Valid date, must be >= subscription_start_date |
| `expiry_date` | date | No | Subscription expiry date | Valid date format (Y-m-d) |
| `termination_date` | date | No | Subscription termination date | Valid date format (Y-m-d) |
| `status` | string | No | Subscription status | Must be one of: "active", "inactive", "suspended", "expired" |

#### Success Response (200 OK)

```json
{
    "success": true,
    "message": "Subscription saved successfully.",
    "data": {
        "id": 1,
        "partner_id": 1,
        "partner_type": "Founding Partner",
        "enrollment_type": "Standard Enrollment",
        "monthly_platform_fee": "597.00",
        "first_reg_amount": "5970.00",
        "first_reg_date": "2025-12-01",
        "installment_type": "10.00",
        "first_reg_payment": "597.00",
        "first_reg_payment_2": "0.00",
        "total_paid_amount": "597.00",
        "subscription_start_date": "2025-12-01",
        "subscription_end_date": "2026-09-30",
        "expiry_date": "2026-09-30",
        "termination_date": null,
        "status": "active",
        "created_at": "2025-12-07T11:00:00.000000Z",
        "updated_at": "2025-12-07T11:00:00.000000Z",
        "deleted_at": null
    }
}
```

#### Error Responses

**Validation Error (422 Unprocessable Entity)**
```json
{
    "success": false,
    "message": "Validation failed.",
    "errors": {
        "partner_id": [
            "Partner ID is required."
        ],
        "subscription_end_date": [
            "Subscription end date must be on or after the subscription start date."
        ]
    }
}
```

---

### 2. Get Partner Subscription Details

**Method:** `POST`  
**Endpoint:** `{{base_url}}/api/v1/admin/partner-subscriptions/{partnerId}`

**Description:** Retrieves subscription details for a specific partner.

#### Path Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `partnerId` | integer | **Yes** | The ID of the partner |

#### Example Request

```
POST {{base_url}}/api/v1/admin/partner-subscriptions/1
```

#### Success Response (200 OK)

```json
{
    "success": true,
    "message": "Subscription details retrieved successfully.",
    "data": {
        "id": 1,
        "partner_id": 1,
        "partner_type": "Founding Partner",
        "enrollment_type": "Standard Enrollment",
        "monthly_platform_fee": "597.00",
        "first_reg_amount": "5970.00",
        "first_reg_date": "2025-12-01",
        "installment_type": "10.00",
        "first_reg_payment": "597.00",
        "first_reg_payment_2": "0.00",
        "total_paid_amount": "597.00",
        "subscription_start_date": "2025-12-01",
        "subscription_end_date": "2026-09-30",
        "expiry_date": "2026-09-30",
        "termination_date": null,
        "status": "active",
        "created_at": "2025-12-07T11:00:00.000000Z",
        "updated_at": "2025-12-07T11:00:00.000000Z",
        "deleted_at": null
    }
}
```

---

## 🧪 Testing Scenarios

### Test Case 1: Valid Subscription
✅ Create a subscription with all fields populated.
- **Expected:** Success (200 OK)

### Test Case 2: Invalid Status Value
❌ Create a subscription with `status: "pending"`
- **Expected:** Validation error - "Status must be one of: active, inactive, suspended, or expired."

### Test Case 3: Subscription End Date Before Start Date
❌ Create a subscription with `subscription_start_date: "2025-12-07"` and `subscription_end_date: "2025-11-07"`
- **Expected:** Validation error - "Subscription end date must be on or after the subscription start date."

### Test Case 4: Non-existent Partner ID
❌ Create a subscription with `partner_id: 99999`
- **Expected:** Validation error - "Selected partner does not exist."

---

## 📦 Postman Collection Setup

1. **Create Environment Variables:**
   - `base_url`: `http://localhost` or your API base URL
   - `access_token`: Your Bearer authentication token

2. **Set up Collection Headers:**
   ```
   Authorization: Bearer {{access_token}}
   Accept: application/json
   Content-Type: application/json
   ```

---

**Last Updated:** 2025-12-07
