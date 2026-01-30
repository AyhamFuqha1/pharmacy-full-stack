<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Pharmacy System - Account Created</title>
</head>
<body style="font-family: Arial, sans-serif; background-color:#f4f6f8; padding:20px;">

<table width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td align="center">
            <table width="600" style="background:#ffffff; padding:30px; border-radius:8px;">
                
                <tr>
                    <td align="center" style="padding-bottom:20px;">
                        <h2 style="color:#2c3e50;">🏥 Pharmacy Management System</h2>
                    </td>
                </tr>

                <tr>
                    <td>
                        <p>Hello <strong>{{ $name }}</strong>,</p>

                        <p>
                            Your account has been successfully created in the
                            <strong>Pharmacy Management System</strong>.
                        </p>

                        <p><strong>Login Details:</strong></p>

                        <ul>
                            <li><strong>Email:</strong> {{ $email }}</li>
                            <li><strong>Temporary Password:</strong> {{ $password }}</li>
                        </ul>

                        <p style="color:#e74c3c;">
                            ⚠️ For security reasons, you are required to change your password
                            on your first login.
                        </p>

                        <p>
                            Please use the credentials above to log in and update your password immediately.
                        </p>

                        <p>
                            If you did not expect this email, please contact the pharmacy administrator.
                        </p>

                        <br>

                        <p>Regards,<br>
                        <strong>Pharmacy System Team</strong></p>
                    </td>
                </tr>

                <tr>
                    <td align="center" style="padding-top:30px; font-size:12px; color:#7f8c8d;">
                        © {{ date('Y') }} Pharmacy Management System. All rights reserved.
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>

</body>
</html>
