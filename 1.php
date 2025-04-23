<?php

// הגדרת אזור זמן הוסרה - שימשה בעיקר ללוגים

// קובץ הלוג המקומי (log.txt) אינו בשימוש יותר.

// פונקציית הלוג הוסרה לחלוטין.


/**
 * מבצע קריאת GET ל-URL ומטפל בשגיאות.
 * (נשמרה אם כי לא בשימוש בגרסה זו).
 *
 * @param string $url ה-URL לקריאה (כולל פרמטרים אם יש).
 * @return string תוכן התגובה.
 * @throws Exception במקרה של כשל בקריאה.
 */
function safeGet(string $url): string {
    // file_get_contents without @
    $resp = file_get_contents($url);
    if ($resp === false) {
        // הודעת השגיאה אינה כוללת את ה-URL בגרסה הציבורית.
        throw new Exception("שגיאה בקריאת GET ל‑URL חיצוני.");
    }
    return $resp;
}


/**
 * מבצע בקשת POST ל-URL עם פרמטרים בגוף הבקשה בפורמט JSON.
 *
 * @param string $url ה-URL הבסיסי לשליחת הבקשה.
 * @param array $params מערך אסוציאטיבי של הפרמטרים שיומר ל-JSON.
 * @return string תוכן התגובה.
 * @throws Exception במקרה של כשל בקריאה או המרת JSON.
 */
function safePost(string $url, array $params): string {
    // המרת מערך הפרמטרים לפורמט JSON
    $body = json_encode($params);
    if ($body === false) {
        throw new Exception("שגיאה בהמרת פרמטרים ל-JSON: " . json_last_error_msg());
    }

    // יצירת ה-stream context עבור בקשת POST עם JSON Body
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n" // ציון שהתוכן הוא JSON
                      . "Accept: application/json\r\n" // ציון שמצפים לתשובת JSON
                      . "Content-Length: " . strlen($body) . "\r\n", // הוספת אורך התוכן (מומלץ)
            'content' => $body, // גוף הבקשה מכיל את ה-JSON
            'timeout' => 30, // הגדרת Timeout לבקשה (שניות)
            'ignore_errors' => true // מאפשר לקבל גוף תגובה גם עבור סטטוסי שגיאה HTTP כמו 404 או 500
        ],
    ]);

    // ביצוע הבקשה.
    $resp = file_get_contents($url, false, $context);

    // בדיקת כשל בביצוע הקריאה עצמה (בעיות רשת/חיבור)
    if ($resp === false) {
         // הודעת השגיאה אינה כוללת את ה-URL בגרסה הציבורית.
        throw new Exception("שגיאה בקריאת POST ל‑URL חיצוני.");
    }

    return $resp;
}

/**
 * מבצעת התחברות ל-API של ימות המשיח באמצעות בקשת POST עם פרמטרים ב-JSON Body, ומחזירה טוקן זמני.
 * הודעות שגיאה במקרה של כשל לא יכללו מידע רגיש.
 *
 * @param string $apiDomain דומיין ה-API.
 * @param string $username שם המשתמש (מספר מערכת).
 * @param string $password סיסמת הניהול.
 * @return string הטוקן הזמני שהתקבל.
 * @throws Exception במקרה של כשל בהתחברות או קבלת טוקן.
 */
function performLogin(string $apiDomain, string $username, string $password): string {
    // בניית ה-URL להתחברות. נתיב קבוע: /ym/api/Login
    $loginUrl = "https://$apiDomain/ym/api/Login";

    // הפרמטרים username ו-password שישלחו ב-JSON Body
    $params = [
        'username' => $username,
        'password' => $password,
    ];

    // לוג התחברות הוסר בגרסה זו.

    try {
        // שימוש ב-safePost לביצוע הבקשה - שולח POST עם JSON Body
        $resp = safePost($loginUrl, $params);

        $json = json_decode($resp, true);

        // בדיקה שהתגובה היא JSON תקין ומכילה טוקן
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($json) || empty($json['token'])) {
             // הודעת שגיאה לא תכלול מידע רגיש.
            throw new Exception("התחברות ל-API נכשלה. תגובה לא תקינה או חסר טוקן.");
        }

        // לוג הצלחת התחברות הוסר בגרסה זו.
        return $json['token'];

    } catch (Exception $e) {
         // שגיאת התחברות
        throw new Exception("שגיאת התחברות ל-API: " . $e->getMessage());
    }
}


/**
 * מוחק קובץ ספציפי באמצעות ה-API של ימות המשיח, תוך שימוש בטוקן זמני.
 * שולח את הבקשה ב-POST עם JSON Body. לוגים הוסרו.
 *
 * @param string $apiDomain דומיין ה-API.
 * @param string $token הטוקן הזמני לשימוש (מהתחברות ראשית).
 * @return void
 */
function deleteFile(string $apiDomain, string $token): void {
    $approvalPath = getenv('YM_APPROVAL_PATH'); // עדיין צריך את הנתיב ממשתני סביבה

    // הפרמטרים לבקשת POST בפורמט מערך שיהפוך ל-JSON
    $params = [
        'token' => $token, // שימוש בטוקן הזמני של המשתמש הראשי (בגוף POST, מאובטח יותר)
        'wath' => "ivr2:$approvalPath", // השם הנכון של הפרמטר הוא כנראה 'wath', לא 'what'
        'action' => 'delete',
    ];

    // ה-URL הבסיסי ללא פרמטרים
    $delUrl = "https://$apiDomain/ym/api/FileAction";

    // לוג מחיקה הוסר.

    try {
        $resp = safePost($delUrl, $params); // שליחת הבקשה ב-POST עם JSON Body

        $json = json_decode($resp, true);

        // בדיקה נוספת שהתגובה היא JSON תקין
         if (json_last_error() !== JSON_ERROR_NONE || !is_array($json)) {
            // לוג שגיאה הוסר.
            return;
        }

        if (strtoupper(($json['responseStatus'] ?? '')) === 'OK') {
            // לוג הצלחה הוסר.
        } else {
            // לוג כישלון הוסר.
        }
    } catch (Exception $e) {
         // לוג שגיאה חריגה הוסר.
         // ממשיכים הלאה למרות הכישלון
    }
}

/* ──────────────────────────────────────────────────────────
    פונקציית הגדרת משתמש חדש
────────────────────────────────────────────────────────── */
/**
 * מגדיר משתמש חדש במערכת ימות המשיח.
 * מבצע התחברות עם פרטי המשתמש החדש ומקבל טוקן זמני לשימוש בבקשות ההגדרה.
 * לוגים הוסרו. הודעות שגיאה יכללו אינדקס רשומה, לא מידע רגיש.
 *
 * @param string $apiDomain דומיין ה-API.
 * @param int $index אינדקס הרשומה בקלט (לצורך זיהוי בשגיאות).
 * @param string $user שם המשתמש של המשתמש החדש.
 * @param string $pass הסיסמה של המשתמש החדש.
 * @param string $phone מספר הטלפון של המשתמש החדש.
 * @throws Exception במקרה של כשל במהלך ההגדרה (כולל התחברות למשתמש החדש).
 * @return void
 */
function setupNewUser(string $apiDomain, int $index, string $user, string $pass, string $phone): void {
    // לוג התחלת הגדרה הוסר.

    $routingNumber = getenv('YM_ROUTING_NUMBER');
    $number1800 = getenv('YM_1800_NUMBER');

    // מנקה את המשתנה $entry אם קיים בלולאה הראשית.
    // זה נעשה כבר בסוף הלולאה הראשית, אך ניקוי מוקדם יותר אפשרי כאן.
    // unset($entry);

    try {
        // שלב חדש: התחברות עם שם המשתמש והסיסמה של המשתמש החדש כדי לקבל טוקן זמני עבורו.
        // **חשוב:** ההתחברות נעשית ב-POST עם פרמטרים ב-JSON Body.
        // performLogin בגרסה זו לא מייצר לוגים ספציפיים עם פרמטרים.
        $userToken = performLogin($apiDomain, $user, $pass);

        // מנקה את הסיסמה והטלפון של המשתמש החדש מהזיכרון מיד לאחר השימוש בהם להתחברות ולהגדרה.
        unset($pass, $phone);

        // מעכשיו, כל הבקשות עבור משתמש זה ישתמשו ב-userToken במקום "$user:$pass"
        $tokenToUse = $userToken;

        // 1) הגדרת נתיב בסיסי (UpdateExtension)
        $url1 = "https://$apiDomain/ym/api/UpdateExtension"; // URL בסיסי
        $params1 = [ // פרמטרים עבור POST Body (יהפכו ל-JSON)
            'token' => $tokenToUse, // שימוש בטוקן הזמני של המשתמש החדש (בגוף POST, מאובטח יותר)
            'path' => 'ivr2:',
            'type' => 'routing_yemot',
            'routing_yemot_number' => $routingNumber,
            'white_list_error_goto' => '/1',
            'white_list' => 'yes',
        ];

        $resp1 = safePost($url1, $params1); // שליחה ב-POST עם JSON

        $json1 = json_decode($resp1, true);
        // בדיקה נוספת שהתגובה היא JSON תקין לפני גישה למפתחות
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($json1)) {
            // הודעת שגיאה תכלול אינדקס, לא מידע רגיש.
            throw new Exception("❌ UpdateExtension נכשל עבור רשומה אינדקס $index (ivr2:). התשובה אינה JSON תקין.");
        }

        if (strtoupper(($json1['responseStatus'] ?? '')) === 'OK') {
            // לוג הצלחה הוסר.
        } else {
             // הודעת שגיאה תכלול אינדקס, לא מידע רגיש.
            throw new Exception("❌ UpdateExtension נכשל עבור רשומה אינדקס $index (ivr2:).");
        }

        // 2) הגדרת ניתוב (UpdateExtension)
        $url2 = "https://$apiDomain/ym/api/UpdateExtension"; // URL בסיסי
        $params2 = [ // פרמטרים עבור POST Body (יהפכו ל-JSON)
            'token' => $tokenToUse, // שימוש בטוקן הזמני של המשתמש החדש (בגוף POST, מאובטח יותר)
            'path' => 'ivr2:1',
            'type' => 'routing_1800',
            'routing_1800' => $number1800,
        ];
        $resp2 = safePost($url2, $params2); // שליחה ב-POST עם JSON

        $json2 = json_decode($resp2, true);
         if (json_last_error() !== JSON_ERROR_NONE || !is_array($json2)) {
            // הודעת שגיאה תכלול אינדקס, לא מידע רגיש.
            throw new Exception("❌ UpdateExtension נכשל עבור רשומה אינדקס $index (ivr2:1). התשובה אינה JSON תקין.");
        }
        if (strtoupper(($json2['responseStatus'] ?? '')) === 'OK') {
            // לוג הצלחה הוסר.
        } else {
             // הודעת שגיאה תכלול אינדקס, לא מידע רגיש.
            throw new Exception("❌ UpdateExtension נכשל עבור רשומה אינדקס $index (ivr2:1).");
        }

        // 3) העלאת קובץ טקסט (UploadTextFile - TTS ריק)
        $url3 = "https://$apiDomain/ym/api/UploadTextFile"; // URL בסיסי
        $params3 = [ // פרמטרים עבור POST Body (יהפכו ל-JSON)
            'token' => $tokenToUse, // שימוש בטוקן הזמני של המשתמש החדש (בגוף POST, מאובטח יותר)
            'what' => 'ivr2:/M1102.tts',
            'contents' => ' ', // תוכן ריק או כל תוכן התחלתי אחר, בתוך ה-JSON
        ];
        $resp3 = safePost($url3, $params3); // שליחה ב-POST עם JSON

        $json3 = json_decode($resp3, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($json3)) {
            // הודעת שגיאה תכלול אינדקס, לא מידע רגיש.
            throw new Exception("❌ UploadTextFile (M1102.tts) נכשל עבור רשומה אינדקס $index. התשובה אינה JSON תקין.");
        }
        if (strtoupper(($json3['responseStatus'] ?? '')) === 'OK') {
            // לוג הצלחה הוסר.
        } else {
            // הודעת שגיאה תכלול אינדקס, לא מידע רגיש.
            throw new Exception("❌ UploadTextFile (M1102.tts) נכשל עבור רשומה אינדקס $index.");
        }

        // 4) העברת קובץ (FileAction - move)
        $url4 = "https://$apiDomain/ym/api/FileAction"; // URL בסיסי
        $params4 = [ // פרמטרים עבור POST Body (יהפכו ל-JSON)
            'token' => $tokenToUse, // שימוש בטוקן הזמני של המשתמש החדש (בגוף POST, מאובטח יותר)
            'what' => 'ivr2:/M1102.tts',
            'action' => 'move',
            'target' => 'ivr2:/M1102.wav',
        ];
        $resp4 = safePost($url4, $params4); // שליחה ב-POST עם JSON

        $json4 = json_decode($resp4, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($json4)) {
            // הודעת שגיאה תכלול אינדקס, לא מידע רגיש.
            throw new Exception("❌ FileAction move נכשל עבור רשומה אינדקס $index. התשובה אינה JSON תקין.");
        }
        if (strtoupper(($json4['responseStatus'] ?? '')) === 'OK') {
            // לוג הצלחה הוסר.
        } else {
            // הודעת שגיאה תכלול אינדקס, לא מידע רגיש.
            throw new Exception("❌ FileAction move נכשל עבור רשומה אינדקס $index.");
        }

        // 5) העלאת רשימה לבנה עם מספר טלפון (UploadTextFile - WhiteList.ini)
        $url5 = "https://$apiDomain/ym/api/UploadTextFile"; // URL בסיסי
         $params5 = [ // פרמטרים עבור POST Body (יהפכו ל-JSON)
            'token' => $tokenToUse, // שימוש בטוקן הזמני של המשתמש החדש (בגוף POST, מאובטח יותר)
            'what' => 'ivr2:WhiteList.ini',
            'contents' => '0' . $phone, // הטלפון בתוך ה-JSON.
        ];
        $resp5 = safePost($url5, $params5); // שליחה ב-POST עם JSON

        $json5 = json_decode($resp5, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($json5)) {
            // הודעת שגיאה תכלול אינדקס, לא מידע רגיש.
            throw new Exception("❌ UploadTextFile WhiteList.ini נכשל עבור רשומה אינדקס $index. התשובה אינה JSON תקין.");
        }
        if (strtoupper(($json5['responseStatus'] ?? '')) === 'OK') {
            // לוג הצלחה הוסר.
        } else {
            // הודעת שגיאה תכלול אינדקס, לא מידע רגיש.
            throw new Exception("❌ UploadTextFile WhiteList.ini נכשל עבור רשומה אינדקס $index.");
        }

    } catch (Exception $e) {
        // שגיאה במהלך הגדרה - נתפסת כאן.
         // לוג שגיאה הוסר. הודעת השגיאה כוללת אינדקס.
         throw new Exception("שגיאה בהגדרת רשומה אינדקס $index: " . $e->getMessage()); // העברת השגיאה הלאה
    }
     // משתנים רגישים נוקו או בתוך try או בתוך catch.
      // לוג סיום הגדרה הוסר.
}

// —————————————————────────────────────────────────────────———
// קוד ראשי: הורדה ועיבוד של נתונים מהשירות
// נקודת הכניסה של הסקריפט.
// —————————————————────────────────────────────────────────———
// לוג התחלה הוסר.

// קבלת הגדרות וסודות מהסביבה
$ymTokenRaw = getenv('YM_TOKEN'); // הטוקן הראשי הגולמי (username:password)
$apiDomain = getenv('YM_API_DOMAIN');
$approvalPath = getenv('YM_APPROVAL_PATH');

// קבלת הגדרות נוספות לניתוב
$routingNumber = getenv('YM_ROUTING_NUMBER');
$number1800 = getenv('YM_1800_NUMBER');


// בדיקה שכל הסודות וההגדרות קיימים
if (empty($ymTokenRaw) || empty($apiDomain) || empty($approvalPath) || empty($routingNumber) || empty($number1800)) {
    // לוג שגיאת סודות חסרים הוסר.
    exit(1); // יציאה עם קוד שגיאה
}

// פיצול הטוקן הראשי לשם משתמש וסיסמה
$tokenParts = explode(':', $ymTokenRaw, 2);
if (count($tokenParts) !== 2 || empty($tokenParts[0]) || empty($tokenParts[1])) {
    // לוג שגיאת פורמט טוקן הוסר.
    // מנקה את המשתנה הרגיש הגולמי מיד במקרה של פורמט שגוי.
    unset($ymTokenRaw, $tokenParts);
    throw new Exception("❌ פורמט YM_TOKEN שגוי. נדרש 'username:password'."); // זורק Exception קריטי
}
$mainUsername = $tokenParts[0];
$mainPassword = $tokenParts[1]; // <-- שם המשתנה הנכון

// מנקה את הטוקן הגולמי ואת החלקים שלו מוקדם ככל האפשר
unset($ymTokenRaw, $tokenParts);

// משתנה לאחסון הטוקן הזמני הראשי
$mainTemporaryToken = null;

try {
    // שלב חדש: התחברות ראשית עם שם המשתמש והסיסמה הראשיים כדי לקבל טוקן זמני לתהליך.
    // performLogin בגרסה זו לא מייצר לוגים ספציפיים עם פרמטרים.
    $mainTemporaryToken = performLogin($apiDomain, $mainUsername, $mainPassword);

    // מנקה את הסיסמה הראשית מהזיכרון מיד לאחר השימוש בהתחברות.
    unset($mainPassword);
    // מנקה את שם המשתמש הראשי מיד לאחר השימוש בו להתחברות
    unset($mainUsername);


    // 1) קבלת JSON מהשרת (RenderYMGRFile) - שימוש בטוקן הזמני הראשי
    $renderUrl = "https://$apiDomain/ym/api/RenderYMGRFile"; // URL בסיסי
    $renderParams = [ // פרמטרים עבור POST Body (יהפכו ל-JSON)
        'token' => $mainTemporaryToken, // שימוש בטוקן הזמני הראשי (בגוף POST, מאובטח יותר)
        'wath' => "ivr2:$approvalPath", // השם הנכון של הפרמטר הוא כנראה 'wath', לא 'what'
        'convertType' => 'json',
        'notLoadLang' => '0',
    ];

    // לוג התחלת הורדה הוסר.
    $response = safePost($renderUrl, $renderParams); // שליחה ב-POST עם JSON

    // לוג הצלחת הורדה הוסר.

    $json = json_decode($response, true);

    // ולידציה בסיסית של מבנה ה-JSON שהתקבל
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($json) || !isset($json['data']) || !is_array($json['data'])) {
        // לוג שגיאת מבנה JSON הוסר.
        throw new Exception("💥 שגיאה במבנה ה-JSON: JSON שהתקבל מה-API אינו תקין או חסר 'data'. מבנה בלתי צפוי."); // זורק Exception קריטי
    }

    $data = $json['data'];
    if (count($data) === 0) {
        // לוג אין רשומות הוסר.
    } else {
        // לוג מספר רשומות הוסר.
        foreach ($data as $i => $entry) {
            // קריאת הנתונים מהרשומה
            $user  = $entry['P050'] ?? null;
            $pass  = $entry['P051'] ?? null;
            $phone = $entry['P052'] ?? null;

            // מנקה את המשתנה $entry לאחר קריאת הנתונים ממנו בתוך הלולאה
            unset($entry);

            // ולידציה של נתוני הרשומה - בדיקה שאינם חסרים ואינם ריקים
            if (empty($user) || empty($pass) || empty($phone)) {
                 // לוג רשומה לא שלמה הוסר.
                // מנקה את המשתנים גם ברשומה שדילגו עליה.
                unset($user, $pass, $phone);
                continue; // מדלגים על רשומה פגומה ועוברים לרשומה הבאה.
            }

            try {
                // הפעלת פונקציית ההגדרה, העברת ה-apiDomain, האינדקס והנתונים הגולמיים.
                // setupNewUser בגרסה זו לא מייצר לוגים מפורטים.
                setupNewUser($apiDomain, $i, $user, $pass, $phone);
                 // המשתנים $user, $pass, $phone נוקו בתוך setupNewUser או בתוך ה-catch שם.
            } catch (Exception $setupException) {
                // שגיאה במהלך הגדרה - נתפסת כאן.
                 // לוג שגיאה הוסר. הודעת השגיאה כוללת אינדקס.
                 throw new Exception("שגיאה בהגדרת רשומה אינדקס $i: " . $setupException->getMessage()); // העברת השגיאה הלאה
            }
             // המשתנים $user, $pass, $phone נוקו או בתוך try או בתוך catch של setupNewUser.
        }
        // לוג סיום עיבוד רשומות הוסר.
    }

} catch (Exception $e) {
    // שגיאות קריטיות (כמו שגיאת התחברות ראשית או שגיאה בהורדת הקובץ).
    // לוג שגיאה קריטית הוסר. הודעת השגיאה מגיעה מאובייקט ה-Exception.
    throw new Exception("💥 שגיאה קריטית במהלך התהליך הראשי: " . $e->getMessage()); // זורק Exception קריטי
} finally {
    // תמיד מוחקים את הקובץ לאחר העיבוד (גם אם היו שגיאות בחלק מהרשומות).
    // המחיקה מתבצעת באמצעות הטוקן הזמני הראשי שהתקבל בהתחברות הראשונית.
    // מוודאים שהטוקן הזמני הראשי קיים לפני מחיקה.
    // לוג מחיקה ב-finally הוסר.
    if (isset($mainTemporaryToken) && !empty($mainTemporaryToken) && isset($apiDomain) && !empty($apiDomain)) {
         deleteFile($apiDomain, $mainTemporaryToken); // קריאה לפונקציית מחיקה עם הטוקן הזמני
    } else {
         // לוג דילוג על מחיקה הוסר.
    }
    // מנקה את הטוקן הזמני הראשי מהזיכרון בסוף התהליך.
    if (isset($mainTemporaryToken)) unset($mainTemporaryToken);

    // לוג סיום התהליך הוסר.
}

// סוף הסקריפט - ניקוי סופי של משתנים רגישים אם עדיין קיימים
// הגנה אחרונה לניקוי משתנים שיכולים להיות רגישים.
unset($mainUsername, $mainPassword, $apiDomain, $ymTokenRaw, $tokenParts, $user, $pass, $phone, $entry, $mainTemporaryToken, $userToken, $tokenToUse);
