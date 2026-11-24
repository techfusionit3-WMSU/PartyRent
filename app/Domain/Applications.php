<?php

namespace App\Domain;

/**
 * Supplier applications (reference services.js "SUPPLIER APPLICATIONS").
 *
 * Individual Supplier: government ID (+ selfie), personal and business information, DTI / barangay /
 * mayor's permit if applicable. Organization / Company Supplier: business structure (SEC / DTI / CDA),
 * BIR COR, business permit, authorized representative + proof of authority, payout.
 * Status: Pending Review → Approved / Rejected / Requires Revision. The applicant becomes the
 * Organization Admin once approved and can then add employees.
 *
 * `suppliers.application` JSON: {type, personal, identity, business, company, representative,
 * docs {key: {name, path, size, uploadedAt}}, applicable [keys], payout, submittedAt, history}.
 */
class Applications
{
    public const STATUS = ['pending' => ['Pending Review', 'b-gold'], 'approved' => ['Approved', 'b-green'], 'rejected' => ['Rejected', 'b-red'], 'revision' => ['Requires Revision', 'b-purple']];

    public const SUPPLIER_TYPES = ['individual' => 'Individual Supplier', 'organization' => 'Organization / Company Supplier'];

    public const COMPANY_TYPES = ['corporation' => 'Corporation', 'partnership' => 'Partnership', 'cooperative' => 'Cooperative', 'sole_proprietorship' => 'Sole Proprietorship'];

    public const ID_TYPES = ['Philippine National ID', "Driver's License", 'Passport', 'UMID', 'PRC ID', 'Postal ID'];

    /** Registration document per company type. */
    public const REGISTRATION = [
        'corporation' => ['sec_certificate', 'SEC Certificate of Incorporation'],
        'partnership' => ['sec_partnership', 'SEC Certificate of Recording and Articles of Partnership'],
        'cooperative' => ['cda_certificate', 'CDA Certificate of Registration'],
        'sole_proprietorship' => ['dti_registration', 'DTI Business Name Registration'],
    ];

    /** Document checklist. need: required | conditional (required when it applies) | recommended | optional */
    public static function requirements(?string $type, ?string $companyType = null): array
    {
        $q = fn ($key, $label, $need, $group, $note = null) => compact('key', 'label', 'need', 'group', 'note');
        if ($type === 'organization') {
            // the company type is not asked any more: one registration document (older applications keep theirs)
            $reg = self::REGISTRATION[$companyType] ?? ['business_registration', 'Business registration certificate (SEC, DTI or CDA)'];

            return [
                $q($reg[0], $reg[1], 'required', 'Business registration'),
                $q('bir_cor', 'BIR Certificate of Registration (COR)', 'required', 'Permits & tax'),
                $q('mayors_permit', "Mayor's / Business Permit", 'required', 'Permits & tax'),
                $q('barangay_clearance', 'Barangay Business Clearance', 'conditional', 'Permits & tax', 'if applicable'),
                $q('other_permits', 'Other operating permits and licenses', 'optional', 'Permits & tax'),
                $q('rep_id', "Authorized representative's government ID", 'required', 'Authorized representative'),
                $q('proof_of_authority', 'Proof of authority to represent the company', 'required', 'Authorized representative'),
                $q('board_resolution', $companyType === 'corporation' ? "Board Resolution or Secretary's Certificate" : ($companyType ? 'Special Power of Attorney (SPA) / authorization' : "Board Resolution, Secretary's Certificate or SPA"), 'conditional', 'Authorized representative', 'when applicable'),
                $q('establishment_photos', 'Photos of the establishment or rental inventory', 'recommended', 'Company verification'),
                $q('address_proof', 'Proof of business address', 'optional', 'Company verification', 'if needed'),
            ];
        }

        return [
            $q('gov_id', 'Valid government-issued ID (photo)', 'required', 'Identity verification', "National ID, driver's license, passport or UMID"),
            $q('selfie_id', 'Selfie holding the ID', 'recommended', 'Identity verification'),
            $q('dti_registration', 'DTI Business Name Registration', 'conditional', 'Business documents', 'if registered as a sole proprietorship'),
            $q('barangay_clearance', 'Barangay Business Clearance', 'conditional', 'Business documents', 'if applicable'),
            $q('mayors_permit', "Mayor's / Business Permit", 'conditional', 'Business documents', 'if applicable'),
        ];
    }

    /** Every document key any applicant can upload (for validation of the upload field names). */
    public static function allDocKeys(): array
    {
        $keys = array_merge(array_column(self::requirements('individual'), 'key'), array_column(self::requirements('organization'), 'key'));
        foreach (array_keys(self::COMPANY_TYPES) as $ct) {
            $keys = array_merge($keys, array_column(self::requirements('organization', $ct), 'key'));
        }

        return array_values(array_unique($keys));
    }

    /** Required documents not attached (conditional ones only when the applicant said they apply). */
    public static function missingDocs(array $app): array
    {
        $docs = (array) ($app['docs'] ?? []);
        $applicable = (array) ($app['applicable'] ?? []);

        return array_values(array_filter(self::requirements($app['type'] ?? null, $app['company']['companyType'] ?? null),
            fn ($q) => ($q['need'] === 'required' || ($q['need'] === 'conditional' && in_array($q['key'], $applicable, true))) && empty($docs[$q['key']])));
    }

    /** First problem with an application, or null. */
    public static function validate(array $app): ?string
    {
        if (! isset(self::SUPPLIER_TYPES[$app['type'] ?? ''])) {
            return 'Choose a supplier type.';
        }
        $req = function ($o, array $keys, string $label) {
            $miss = array_filter($keys, fn ($k) => trim((string) (((array) $o)[$k] ?? '')) === '');

            return $miss ? $label.': please complete '.implode(', ', $miss).'.' : null;
        };
        if ($app['type'] === 'individual') {
            if ($e = $req($app['personal'] ?? [], ['fullName', 'birthDate', 'address', 'phone', 'email'], 'Personal information')) {
                return $e;
            }
            if ($e = $req($app['identity'] ?? [], ['idType', 'idNumber'], 'Identity verification')) {
                return $e;
            }
            if ($e = $req($app['business'] ?? [], ['address', 'productTypes', 'phone'], 'Business information')) {
                return $e;
            }
        } else {
            if ($e = $req($app['company'] ?? [], ['legalName', 'address', 'email', 'phone', 'natureOfBusiness'], 'Company information')) {
                return $e;
            }
            $ct = $app['company']['companyType'] ?? null; // optional (no longer asked)
            if ($ct !== null && $ct !== '' && ! isset(self::COMPANY_TYPES[$ct])) {
                return 'Unknown company type.';
            }
            if ($e = $req($app['representative'] ?? [], ['name', 'position', 'idType', 'email', 'phone'], 'Authorized representative')) {
                return $e;
            }
        }
        if ($e = $req($app['payout'] ?? [], ['method', 'provider', 'accountName', 'accountNumber'], 'Payout information')) {
            return $e;
        }
        if ($app['type'] === 'organization' && trim((string) ($app['payout']['financeContact'] ?? '')) === '') {
            return 'Payout information: name the person responsible for financial transactions.';
        }
        if ($miss = self::missingDocs($app)) {
            return 'Missing required document(s): '.implode(', ', array_column($miss, 'label')).'.';
        }

        return null;
    }
}
