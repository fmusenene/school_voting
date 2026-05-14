# Email Configuration for Password Reset

## Current Status (Local Testing)
The password reset feature **already works locally for testing**. When you click "Send Reset Link", the system:
1. Generates a secure token
2. Saves it to the database
3. Displays the reset link directly on the page (since email is not configured)
4. You can click this link to test the complete password reset flow

This is intentional for local development - you can test the entire flow without needing email configuration.

## Option 1: Configure XAMPP Mail (Simple)

To enable email sending in XAMPP:

1. **Edit php.ini:**
   - Open `C:\xampp\php\php.ini`
   - Find the `[mail function]` section
   - Configure these settings:

   ```ini
   [mail function]
   SMTP = smtp.gmail.com
   smtp_port = 587
   sendmail_from = amarumugisha@gmail.com
   sendmail_path = "\"C:\xampp\sendmail\sendmail.exe\" -t"
   ```

2. **Configure sendmail.ini:**
   - Open `C:\xampp\sendmail\sendmail.ini`
   - Update these settings:

   ```ini
   smtp_server=smtp.gmail.com
   smtp_port=587
   smtp_ssl=tls
   auth_username=amarumugisha@gmail.com
   auth_password=your_app_password
   ```

3. **Important for Gmail:**
   - You need to use an App Password, not your regular password
   - Enable 2-factor authentication on your Gmail account
   - Generate an App Password at: https://myaccount.google.com/apppasswords
   - Use that 16-character App Password in sendmail.ini

4. **Restart Apache:**
   - Stop and start Apache in XAMPP Control Panel

## Option 2: Use PHPMailer (Recommended for Production)

For a more robust solution, install PHPMailer:

1. **Install via Composer:**
   ```bash
   composer require phpmailer/phpmailer
   ```

2. **Update the API endpoint:**
   - Modify `api/request_password_reset.php` to use PHPMailer instead of mail()

3. **Example PHPMailer code:**
   ```php
   use PHPMailer\PHPMailer\PHPMailer;
   use PHPMailer\PHPMailer\Exception;

   $mail = new PHPMailer(true);
   $mail->isSMTP();
   $mail->Host = 'smtp.gmail.com';
   $mail->SMTPAuth = true;
   $mail->Username = 'amarumugisha@gmail.com';
   $mail->Password = 'your_app_password';
   $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
   $mail->Port = 587;
   $mail->setFrom('noreply@schoolvoting.com', 'School Voting System');
   $mail->addAddress($admin['email']);
   $mail->isHTML(true);
   $mail->Subject = $subject;
   $mail->Body = $message;
   $mail->send();
   ```

## Testing Without Email Configuration

The current implementation is designed for local testing:
- Submit your email on the forgot password page
- The reset link appears on the page
- Click the link to reset your password
- This works without any email configuration

This is perfect for development and testing the complete flow.
