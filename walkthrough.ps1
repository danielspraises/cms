$ErrorActionPreference = "Stop"
$base = "http://127.0.0.1:8000/api"

try {
    Invoke-WebRequest -Uri "http://127.0.0.1:8000/up" -UseBasicParsing | Out-Null
} catch {
    Write-Host "The Laravel server is not answering. Start it in Window 1 (php artisan serve), then run this again."
    exit 1
}

function Login($email) {
    $r = Invoke-RestMethod -Uri "$base/auth/login" -Method Post -ContentType "application/json" -Headers @{ Accept = "application/json" } -Body (@{ email = $email; password = "password123" } | ConvertTo-Json)
    return @{ Authorization = "Bearer $($r.token)"; Accept = "application/json" }
}
function Api($method, $path, $headers, $body) {
    $req = @{ Uri = "$base$path"; Method = $method; Headers = $headers; ContentType = "application/json" }
    if ($null -ne $body) { $req.Body = ($body | ConvertTo-Json -Depth 6) }
    Invoke-RestMethod @req
}
function Money($minor) { '{0:N2}' -f ($minor / 100) }
function LedgerCount($headers) { (Api Get "/societies/1/members/MU-000001/ledger" $headers $null).total }

try {
    $admin = Login "societyadmin@test.com"
    $treasurer = Login "treasurer@test.com"
    $president = Login "president@test.com"
    $amaka = Login "amaka@test.com"
    Write-Host "Logged in as all four."

    # 12% a year, up to 12 months, on the loan type
    $types = Api Get "/societies/1/loan-types" $admin $null
    $typeId = @($types)[0].id
    Api Patch "/societies/1/loan-types/$typeId" $admin @{
        max_tenure_months = 12
        interest_bands = @(@{ from_months = 1; to_months = 12; rate_percent = 12 })
    } | Out-Null
    Write-Host "Loan type set to 12% a year."

    # Amaka applies for 5,000,000.00 over 6 months
    $app = Api Post "/loan-applications" $amaka @{ loan_product_id = $typeId; requested_principal_minor = 500000000; requested_tenure_months = 6 }
    $id = $app.id
    Write-Host "Application #$id created."

    # The review chain
    Api Post "/loan-applications/$id/act" $admin @{ decision = "recommend"; notes = "Checked." } | Out-Null
    Api Post "/loan-applications/$id/act" $treasurer @{ decision = "recommend"; notes = "Confirmed." } | Out-Null
    Api Post "/loan-applications/$id/act" $president @{ decision = "approve" } | Out-Null
    Write-Host "Approved. It should now be at the Settlement step."

    # What the settlement sheet shows
    $sheet = Api Get "/loan-applications/$id/disbursement" $admin $null
    Write-Host ("Current step: {0}" -f $sheet.stage.name)
    Write-Host ("Interest: {0} at {1}% a year" -f (Money $sheet.terms.interest_minor), ($sheet.terms.interest_rate_bp / 100))
    Write-Host "Earlier loans still owing:"
    foreach ($l in $sheet.outstanding_loans) { Write-Host ("  Loan #{0}: principal {1}" -f $l.loan_id, (Money $l.balances.principal)) }

    $before = LedgerCount $admin

    # Society admin: clear the principal of every earlier loan from the proceeds
    $lines = @($sheet.outstanding_loans | Where-Object { $_.balances.principal -gt 0 } | ForEach-Object {
        @{ loan_id = $_.loan_id; fund_type = "principal"; amount_minor = $_.balances.principal }
    })
    $prepared = Api Post "/loan-applications/$id/disbursement/prepare" $admin @{ settlements = $lines; notes = "Clearing the earlier loan from proceeds" }
    Write-Host ("Sheet prepared. Cleared {0}, net payout {1}" -f (Money $prepared.disbursement.settlement_total_minor), (Money $prepared.disbursement.net_payout_minor))

    # Treasurer: record the payout
    $paid = Api Post "/loan-applications/$id/disbursement/pay" $treasurer @{ paid_on = (Get-Date -Format yyyy-MM-dd); payment_method = "bank_transfer"; payment_reference = "TRF-DEMO-001" }
    $newEntries = (LedgerCount $admin) - $before
    Write-Host ("Payout recorded: {0}, ref {1}. New ledger entries so far: {2}" -f $paid.disbursement.status, $paid.disbursement.payment_reference, $newEntries)

    # Society admin: cross-check and post
    $done = Api Post "/loan-applications/$id/disbursement/confirm" $admin @{ notes = "Matches the bank record" }
    Write-Host ("Posted. Application status: {0}, new loan #{1}" -f $done.application.status, $done.application.loan_id)

    # What went into the books
    $latest = (Api Get "/societies/1/members/MU-000001/ledger" $admin $null).data[0]
    Write-Host "Ledger entry: $($latest.description)"
    foreach ($ln in $latest.lines) {
        Write-Host ("  {0,-16} {1,-7} {2,16}" -f $ln.account.account_type, $ln.direction, (Money $ln.amount_minor))
    }
} catch {
    Write-Host ""
    Write-Host "STOPPED. $($_.Exception.Message)"
    Write-Host "While running: $($_.InvocationInfo.Line.Trim())"
    if ($_.ErrorDetails -and $_.ErrorDetails.Message) { Write-Host "Server said: $($_.ErrorDetails.Message)" }
}