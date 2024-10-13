<?php
require_once __DIR__ . '/../../apiHead.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../../../common/libs/PHPMailer/Exception.php';
require_once __DIR__ . '/../../../common/libs/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../../../common/libs/PHPMailer/SMTP.php';

function sendEmail($user, $instanceID, $subject, $html = false, $template = false, $emailData = false) {
    global $DBLIB, $CONFIG, $TWIG, $bCMS, $CONFIGCLASS;
	if (!$user or $user["userData"]["users_email"] == '') return false; //If the user hasn't entered an E-Mail address yet

    if ($instanceID) {
        $DBLIB->join("instancePositions", "userInstances.instancePositions_id=instancePositions.instancePositions_id", "LEFT");
        $DBLIB->join("instances", "instancePositions.instances_id=instances.instances_id", "LEFT");
        $DBLIB->where("users_userid", $user["userData"]['users_userid']);
        $DBLIB->where("instances.instances_id", $instanceID);
        $DBLIB->where("userInstances_deleted", 0);
        $DBLIB->where("(userInstances.userInstances_archived IS NULL OR userInstances.userInstances_archived >= '" . date('Y-m-d H:i:s') . "')");
        $DBLIB->where("instances.instances_deleted", 0);
        $instance = $DBLIB->getone("userInstances", ["instances.instances_name", "instances.instances_address", "instances.instances_emailHeader"]);
    } else $instance = false;

    $outputHTML = $TWIG->render('api/notifications/email/email_template.twig', ["SUBJECT" => $subject, "HTML"=> $bCMS->cleanString($html), "CONFIG" => $CONFIG, "DATA" => $emailData, "TEMPLATE" => $template, "INSTANCE" => $instance]); // Subject is escaped by twig, but the HTML is not.

    if ($CONFIGCLASS->get('EMAILS_ENABLED') !== "Enabled") {
        return true;
    } elseif ($CONFIGCLASS->get('EMAILS_PROVIDER') == 'Sendgrid') {
        if ($CONFIGCLASS->get('EMAILS_PROVIDERS_SENDGRID_APIKEY')) {
            $email = new \SendGrid\Mail\Mail();
            $email->setFrom($CONFIGCLASS->get('EMAILS_FROMEMAIL'), $CONFIG['PROJECT_NAME']);
            $email->setSubject($bCMS->cleanString($subject));  //Subject should be escaped
            $email->addTo($user["userData"]["users_email"], $user["userData"]["users_name1"] .  ' ' . $user["userData"]["users_name2"]);
            //$email->addContent("text/plain", "and easy to do anywhere, even with PHP");
            $email->addContent("text/html", $outputHTML);
            $sendgrid = new \SendGrid($CONFIGCLASS->get('EMAILS_PROVIDERS_SENDGRID_APIKEY'));
            $response = $sendgrid->send($email);
            if ($response->statusCode() == 202) {
                $sqldata = Array ("users_userid" => $user['userData']['users_userid'],
                    "emailSent_html" => $outputHTML,
                    "emailSent_subject" => $bCMS->cleanString($subject),
                    "emailSent_sent" => date('Y-m-d G:i:s'),
                    "emailSent_fromEmail" => $CONFIGCLASS->get('EMAILS_FROMEMAIL'),
                    "emailSent_fromName" => $CONFIG['PROJECT_NAME'],
                    'emailSent_toEmail' => $user["userData"]["users_email"],
                    'emailSent_toName' => $user["userData"]["users_name1"] .  ' ' . $user["userData"]["users_name2"]
                );
                $emailid = $DBLIB->insert('emailSent', $sqldata);
                if(!$emailid) return false;
                else return true;
            } else return false;
        } else {
            trigger_error("Sendgrid API Key not set", E_USER_WARNING);
            return true;
        }
    } elseif ($CONFIGCLASS->get('EMAILS_PROVIDER') == 'SMTP') {
        $mail = new PHPMailer();

        $mail->isSMTP();
        $mail->Host = $CONFIGCLASS->get('SMTP_HOST');
        $mail->SMTPAuth = true;
        $mail->Username = $CONFIGCLASS->get('SMTP_USERNAME');
        $mail->Password = $CONFIGCLASS->get('SMTP_PASSWORD');
        $mail->SMTPSecure = $CONFIGCLASS->get('SMTP_SECURE');
        $mail->Port = $CONFIGCLASS->get('SMTP_PORT');

        $mail->setFrom($CONFIGCLASS->get('EMAILS_FROMEMAIL'), $CONFIG['PROJECT_NAME']);
        $mail->addAddress($user["userData"]["users_email"], $user["userData"]["users_name1"] . ' ' . $user["userData"]["users_name2"]);
        $mail->Subject = $bCMS->cleanString($subject);
        $mail->isHTML(true);
        $mail->Body = $outputHTML;

        if ($mail->send()) {
            $sqldata = [
                "users_userid" => $user['userData']['users_userid'],
                "emailSent_html" => $outputHTML,
                "emailSent_subject" => $bCMS->cleanString($subject),
                "emailSent_sent" => date('Y-m-d G:i:s'),
                "emailSent_fromEmail" => $CONFIGCLASS->get('EMAILS_FROMEMAIL'),
                "emailSent_fromName" => $CONFIG['PROJECT_NAME'],
                'emailSent_toEmail' => $user["userData"]["users_email"],
                'emailSent_toName' => $user["userData"]["users_name1"] . ' ' . $user["userData"]["users_name2"],
            ];
            $emailid = $DBLIB->insert('emailSent', $sqldata);
            return $emailid ? true : false;
        } else {
            return false;
        }
    } else {
        trigger_error("Email provider not set", E_USER_WARNING);
        return true;
    }
}

/** @OA\Get(
 *     path="/notifications/email/email.php", 
 *     summary="Email Notifications", 
 *     description="Send an email to the user. This returns a function to call rather than a response.", 
 *     operationId="emailNotifications", 
 *     tags={"notifications"}, 
 *     )
 */
