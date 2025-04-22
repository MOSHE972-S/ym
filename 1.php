<?php

// הגדרת אזור זמן
date_default_timezone_set('Asia/Jerusalem');

// קובץ הלוג המקומי (log.txt) אינו בשימוש יותר. הלוגים מודפסים לקונסולה בלבד (שתועבר ללוגים של GitHub Actions).
// $logFile = __DIR__ . '/log.txt';

/**
 * כותב שורה ללוג (קונסולה בלבד).
 * נמנע לחלוטין מרישום מידע רגיש כאן.
 *
 * @param string $line השורה לכתיבה.
 * @return void
 */
function logLine(string $line): void {
    $time = date('Y-m-d H:i:s');
    // רק מדפיסים לקונסולה. GitHub Actions יאסוף את הפלט הזה.
    echo "[$time] $line\n";
    // אין כתיבה לקובץ מקומי: file_put_contents($logFile, $fullLine, FILE_APPEND);
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
        // שגיאה בהמרת המערך ל-JSON
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
        // לא כולל את ה-URL או הפרמטרים בהודעת שגיאה.
        throw new Exception("שגיאה בקריאת POST ל‑URL חיצוני.");
    }

    // הערה: כפי שצוין קודם, ignore_errors = true גורם לכך שגם שגיאות HTTP (כמו 404)
    // יחזירו גוף תגובה ולא false. הטיפול בשגיאות API ייעשה ע"י בדיקת ה-responseStatus ב-JSON.

    return $resp;
}

/**
 * מבצעת התחברות ל-API של ימות המשיח ומחזירה טוקן זמני.
 *
 * @param string $apiDomain דומיין ה-API.
 * @param string $username שם המשתמש (מספר מערכת).
 * @param string $password סיסמת הניהול.
 * @return string הטוקן הזמני שהתקבל.
 * @throws Exception במקרה של כשל בהתחברות או קבלת טוקן.
 */
function performLogin(string $apiDomain, string $username, string $password): string {
    $loginUrl = "https://$apiDomain/ym/api/?Login"; // נקודת קצה להתחברות
    $params = [
        'username' => $username,
        'password' => $password,
    ];

    logLine("🔑 מנסה להתחבר ל-API עבור משתמש: " . (!empty($username) ? $username : 'חסר'));

    try {
        $resp = safePost($loginUrl, $params);

        $json = json_decode($resp, true);

        // בדיקה שהתגובה היא JSON תקין ומכילה טוקן
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($json) || empty($json['token'])) {
             // נמנעים מלוג התגובה המלאה.
            throw new Exception("התחברות נכשלה עבור משתמש: " . (!empty($username) ? $username : 'חסר') . ". תגובה לא תקינה או חסר טוקן.");
        }

        logLine("✅ התחברות הצליחה, טוקן זמני התקבל.");
        return $json['token'];

    } catch (Exception $e) {
         // שגיאת התחברות
        throw new Exception("שגיאת התחברות ל-API: " . $e->getMessage());
    }
}


/**
 * מוחק קובץ ספציפי באמצעות ה-API של ימות המשיח, תוך שימוש בטוקן זמני.
 *
 * @param string $apiDomain דומיין ה-API.
 * @param string $token הטוקן הזמני לשימוש (מהתחברות ראשית).
 * @return void
 */
function deleteFile(string $apiDomain, string $token): void {
    $approvalPath = getenv('YM_APPROVAL_PATH'); // עדיין צריך את הנתיב ממשתני סביבה

    // הפרמטרים לבקשת POST בפורמט מערך שיהפוך ל-JSON
    $params = [
        'token' => $token, // הטוקן הזמני שהתקבל מהתחברות ראשית
        'what' => "ivr2:$approvalPath",
        'action' => 'delete',
    ];

    // ה-URL הבסיסי ללא פרמטרים
    $delUrl = "https://$apiDomain/ym/api/FileAction";

    logLine("🧹 מנסה למחוק את קובץ המקור ב-POST (JSON)...");

    try {
        $resp = safePost($delUrl, $params); // שליחת הבקשה ב-POST עם JSON Body

        $json = json_decode($resp, true);

        // בדיקה נוספת שהתגובה היא JSON תקין
         if (json_last_error() !== JSON_ERROR_NONE || !is_array($json)) {
            logLine("⚠️ FileAction delete נכשל. התגובה אינה JSON תקין.");
            return;
        }

        if (strtoupper(($json['responseStatus'] ?? '')) === 'OK') {
            logLine("🗑️ FileAction delete הצליח.");
        } else {
            // נמנעים מרישום התגובה המלאה גם כאן.
            logLine("⚠️ FileAction delete נכשל או לא ברור.");
        }
    } catch (Exception $e) {
         logLine("⚠️ FileAction delete נכשל (שגיאת POST ל-API): " . $e->getMessage());
         // ממשיכים הלאה למרות הכישלון
    }
}

/* ──────────────────────────────────────────────────────────
    פונקציית הגדרת משתמש חדש
────────────────────────────────────────────────────────── */
/**
 * מגדיר משתמש חדש במערכת ימות המשיח.
 * מבצע התחברות עם פרטי המשתמש החדש ומקבל טוקן זמני לשימוש בבקשות ההגדרה.
 *
 * @param string $apiDomain דומיין ה-API.
 * @param int $index אינדקס הרשומה בקלט (לצורך לוגים).
 * @param string $user שם המשתמש של המשתמש החדש.
 * @param string $pass הסיסמה של המשתמש החדש.
 * @param string $phone מספר הטלפון של המשתמש החדש.
 * @throws Exception במקרה של כשל במהלך ההגדרה (כולל התחברות למשתמש החדש).
 * @return void
 */
function setupNewUser(string $apiDomain, int $index, string $user, string $pass, string $phone): void {
    // רישום לוג ללא פרטים רגישים, תוך שימוש באינדקס הרשומה
    logLine("📦 התחלת הגדרות עבור רשומה אינדקס $index (משתמש: " . (!empty($user) ? $user : 'חסר') . ")"); // השארת שם המשתמש בלוג זה לצורך מעקב נוח יותר, שכן הסיסמה והטלפון אינם נרשמים והטוקן נשלח ב-POST+JSON. אם גם שם המשתמש נחשב רגיש מדי ללוגים, יש להסירו.

    $routingNumber = getenv('YM_ROUTING_NUMBER');
    $number1800 = getenv('YM_1800_NUMBER');

    try {
        // שלב חדש: התחברות עם שם המשתמש והסיסמה של המשתמש החדש כדי לקבל טוקן זמני עבורו.
        $userToken = performLogin($apiDomain, $user, $pass);

        // מעכשיו, כל הבקשות עבור משתמש זה ישתמשו ב-userToken במקום "$user:$pass"
        $tokenToUse = $userToken;

        // 1) הגדרת נתיב בסיסי (UpdateExtension)
        $url1 = "https://$apiDomain/ym/api/UpdateExtension"; // URL בסיסי
        $params1 = [ // פרמטרים עבור POST Body (יהפכו ל-JSON)
            'token' => $tokenToUse, // שימוש בטוקן הזמני של המשתמש החדש
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
            throw new Exception("❌ UpdateExtension נכשל עבור רשומה אינדקס $index (ivr2:). התגובה אינה JSON תקין.");
        }

        if (strtoupper(($json1['responseStatus'] ?? '')) === 'OK') {
            logLine("✅ UpdateExtension הצליח עבור רשומה אינדקס $index (ivr2:)");
        } else {
            throw new Exception("❌ UpdateExtension נכשל עבור רשומה אינדקס $index (ivr2:).");
        }

        // 2) הגדרת ניתוב (UpdateExtension)
        $url2 = "https://$apiDomain/ym/api/UpdateExtension"; // URL בסיסי
        $params2 = [ // פרמטרים עבור POST Body (יהפכו ל-JSON)
            'token' => $tokenToUse, // שימוש בטוקן הזמני של המשתמש החדש
            'path' => 'ivr2:1',
            'type' => 'routing_1800',
            'routing_1800' => $number1800,
        ];
        $resp2 = safePost($url2, $params2); // שליחה ב-POST עם JSON

        $json2 = json_decode($resp2, true);
         if (json_last_error() !== JSON_ERROR_NONE || !is_array($json2)) {
            throw new Exception("❌ UpdateExtension נכשל עבור רשומה אינדקס $index (ivr2:1). התגובה אינה JSON תקין.");
        }
        if (strtoupper(($json2['responseStatus'] ?? '')) === 'OK') {
            logLine("✅ UpdateExtension הצליח עבור רשומה אינדקס $index (ivr2:1)");
        } else {
            throw new Exception("❌ UpdateExtension נכשל עבור רשומה אינדקס $index (ivr2:1).");
        }

        // 3) העלאת קובץ טקסט (UploadTextFile - TTS ריק)
        $url3 = "https://$apiDomain/ym/api/UploadTextFile"; // URL בסיסי
        $params3 = [ // פרמטרים עבור POST Body (יהפכו ל-JSON)
            'token' => $tokenToUse, // שימוש בטוקן הזמני של המשתמש החדש
            'what' => 'ivr2:/M1102.tts',
            'contents' => ' ', // תוכן ריק או כל תוכן התחלתי אחר, בתוך ה-JSON
        ];
        $resp3 = safePost($url3, $params3); // שליחה ב-POST עם JSON

        $json3 = json_decode($resp3, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($json3)) {
            throw new Exception("❌ UploadTextFile (M1102.tts) נכשל עבור רשומה אינדקס $index. התגובה אינה JSON תקין.");
        }
        if (strtoupper(($json3['responseStatus'] ?? '')) === 'OK') {
            logLine("✅ UploadTextFile הצליח עבור רשומה אינדקס $index M1102.tts");
        } else {
            throw new Exception("❌ UploadTextFile (M1102.tts) נכשל עבור רשומה אינדקס $index.");
        }

        // 4) העברת קובץ (FileAction - move)
        $url4 = "https://$apiDomain/ym/api/FileAction"; // URL בסיסי
        $params4 = [ // פרמטרים עבור POST Body (יהפכו ל-JSON)
            'token' => $tokenToUse, // שימוש בטוקן הזמני של המשתמש החדש
            'what' => 'ivr2:/M1102.tts',
            'action' => 'move',
            'target' => 'ivr2:/M1102.wav',
        ];
        $resp4 = safePost($url4, $params4); // שליחה ב-POST עם JSON

        $json4 = json_decode($resp4, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($json4)) {
            throw new Exception("❌ FileAction move נכשל עבור רשומה אינדקס $index. התגובה אינה JSON תקין.");
        }
        if (strtoupper(($json4['responseStatus'] ?? '')) === 'OK') {
            logLine("✅ FileAction move הצליח עבור רשומה אינדקס $index");
        } else {
            throw new Exception("❌ FileAction move נכשל עבור רשומה אינדקס $index.");
        }

        // 5) העלאת רשימה לבנה עם מספר טלפון (UploadTextFile - WhiteList.ini)
        $url5 = "https://$apiDomain/ym/api/UploadTextFile"; // URL בסיסי
         $params5 = [ // פרמטרים עבור POST Body (יהפכו ל-JSON)
            'token' => $tokenToUse, // שימוש בטוקן הזמני של המשתמש החדש
            'what' => 'ivr2:WhiteList.ini',
            'contents' => $phone, // הטלפון בתוך ה-JSON (מאובטח יותר)
        ];
        $resp5 = safePost($url5, $params5); // שליחה ב-POST עם JSON

        $json5 = json_decode($resp5, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($json5)) {
            throw new Exception("❌ UploadTextFile WhiteList.ini נכשל עבור רשומה אינדקס $index. התגובה אינה JSON תקין.");
        }
        if (strtoupper(($json5['responseStatus'] ?? '')) === 'OK') {
            // רישום לוג הצלחה ללא הטלפון המלא
            logLine("✅ UploadTextFile הצליח עבור רשומה אינדקס $index WhiteList.ini");
        } else {
            throw new Exception("❌ UploadTextFile WhiteList.ini נכשל עבור רשומה אינדקס $index.");
        }

    } catch (Exception $e) {
        // שגיאה במהלך הגדרת רשומה זו (כולל שגיאת התחברות עבורה)
         logLine("🚨 סיום טיפול ברשומה אינדקס $index עקב שגיאה: " . $e->getMessage());
         // אם רוצים להפסיק הכל ברגע שיש שגיאה באחת הרשומות, יש להוסיף כאן throw $e;
         // כרגע ממשיכים לרשומה הבאה גם אם הנוכחית נכשלה.
    }

    logLine("🎉 סיום הגדרות עבור רשומה אינדקס $index\n");
}

// ————————————————————————————————————————————————————————————
// קוד ראשי: הורדה ועיבוד של נתונים מהשירות
logLine("🚀 התחלת תהליך קבלת נתונים והגדרת משתמשים...");

// קבלת הגדרות וסודות מהסביבה
$ymTokenRaw = getenv('YM_TOKEN'); // הטוקן הראשי הגולמי (username:password)
$apiDomain = getenv('YM_API_DOMAIN');
$approvalPath = getenv('YM_APPROVAL_PATH');

// קבלת הגדרות נוספות לניתוב
$routingNumber = getenv('YM_ROUTING_NUMBER');
$number1800 = getenv('YM_1800_NUMBER');


// בדיקה שכל הסודות וההגדרות קיימים
if (empty($ymTokenRaw) || empty($apiDomain) || empty($approvalPath) || empty($routingNumber) || empty($number1800)) {
    logLine("❌ חסרים סודות או הגדרות. ודא שהגדרת את כל משתני הסביבה הנדרשים (YM_TOKEN, YM_API_DOMAIN, YM_APPROVAL_PATH, YM_ROUTING_NUMBER, YM_1800_NUMBER) ב-GitHub Secrets או בסביבת ההרצה.");
    exit(1); // יציאה עם קוד שגיאה
}

// פיצול הטוקן הראשי לשם משתמש וסיסמה
$tokenParts = explode(':', $ymTokenRaw, 2);
if (count($tokenParts) !== 2 || empty($tokenParts[0]) || empty($tokenParts[1])) {
    logLine("❌ פורמט YM_TOKEN שגוי. נדרש 'username:password'.");
    exit(1); // יציאה עם קוד שגיאה
}
$mainUsername = $tokenParts[0];
$mainPassword = $tokenParts[1];

// הטוקן הגולמי YM_TOKEN כבר לא יהיה בשימוש ישיר אחרי הפיצול.
// נשתמש במשתנים mainUsername ו-mainPassword כדי להתחבר.
unset($ymTokenRaw, $tokenParts, $mainPassword); // מנקה את המשתנים הרגישים מהזיכרון מוקדם ככל האפשר

try {
    // שלב חדש: התחברות ראשית עם הטוקן הראשי כדי לקבל טוקן זמני לתהליך.
    $mainTemporaryToken = performLogin($apiDomain, $mainUsername, $password); // שימוש במשתנה $password מהפיצול

    // הטוקן הראשי הגולמי לא נמצא יותר בזיכרון (בעקבות unset).
    // המשתנה mainUsername כבר אינו מכיל את הסיסמה.


    // 1) קבלת JSON מהשרת (RenderYMGRFile) - שימוש בטוקן הזמני הראשי
    $renderUrl = "https://$apiDomain/ym/api/RenderYMGRFile"; // URL בסיסי
    $renderParams = [ // פרמטרים עבור POST Body (יהפכו ל-JSON)
        'token' => $mainTemporaryToken, // שימוש בטוקן הזמני הראשי
        'what' => "ivr2:$approvalPath",
        'convertType' => 'json',
        'notLoadLang' => '0',
    ];

    logLine("🔽 מנסה להוריד קובץ נתונים (POST + JSON) עם טוקן זמני...");
    $response = safePost($renderUrl, $renderParams); // שליחה ב-POST עם JSON

    logLine("✅ קובץ נתונים הורד בהצלחה. מפענח JSON...");
    $json = json_decode($response, true);

    // ולידציה בסיסית של מבנה ה-JSON שהתקבל
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($json) || !isset($json['data']) || !is_array($json['data'])) {
        // לא נרשם את התגובה המלאה במקרה של מבנה JSON שגוי.
        throw new Exception("JSON שהתקבל מה-API אינו תקין או חסר 'data'. מבנה בלתי צפוי.");
    }

    $data = $json['data'];
    if (count($data) === 0) {
        logLine("ℹ️ אין רשומות בקובץ.");
    } else {
        logLine("📊 נמצאו " . count($data) . " רשומות לעיבוד.");
        foreach ($data as $i => $entry) {
            // קריאת הנתונים מהרשומה
            $user  = $entry['P050'] ?? null;
            $pass  = $entry['P051'] ?? null;
            $phone = $entry['P052'] ?? null;

            // ולידציה של נתוני הרשומה - בדיקה שאינם חסרים ואינם ריקים
            if (empty($user) || empty($pass) || empty($phone)) {
                 // נמנעים מרישום הנתונים החסרים או הריקים ללוג במפורש
                logLine("❌ רשומה אינדקס $i לא שלמה או מכילה שדות ריקים (P050/P051/P052). מדלג על רשומה זו.");
                continue; // מדלגים על רשומה פגומה ועוברים לרשומה הבאה.
            }

            // פיצול שם המשתמש מהסיסמה מהקלט - לא רלוונטי כאן, זה כבר מפוצל P050=user, P051=pass
            // $userTokenRaw = "$user:$pass"; // לא בשימוש יותר
            // $userTokenParts = explode(':', $userTokenRaw, 2); // לא בשימוש יותר
            // $newUsername = $userTokenParts[0]; // זה $user
            // $newUserPassword = $userTokenParts[1]; // זה $pass

            try {
                // הפעלת פונקציית ההגדרה, העברת האינדקס והנתונים הגולמיים.
                // הפונקציה תבצע התחברות משלה עם פרטי המשתמש/סיסמה הללו.
                setupNewUser($apiDomain, $i, $user, $pass, $phone);
            } catch (Exception $setupException) {
                // שגיאה במהלך הגדרת רשומה זו (כולל שגיאת התחברות עבורה)
                 logLine("🚨 סיום טיפול ברשומה אינדקס $i עקב שגיאה: " . $setupException->getMessage());
                 // ממשיכים לרשומה הבאה.
            }
             // מנקה את פרטי המשתמש והסיסמה הספציפיים לרשומה מהזיכרון לאחר הטיפול
             unset($user, $pass, $phone, $entry);
        }
        logLine("✅ סיום עיבוד כל הרשומות.");
    }

} catch (Exception $e) {
    // רישום שגיאות כלליות קריטיות (כמו שגיאת התחברות ראשית או שגיאה בהורדת הקובץ)
    logLine("💥 שגיאה קריטית במהלך התהליך הראשי: " . $e->getMessage());
    exit(1); // יציאה עם קוד שגיאה במקרה של שגיאה קריטית.
} finally {
    // תמיד מוחקים את קובץ המקור לאחר העיבוד (גם אם היו שגיאות בחלק מהרשומות).
    // המחיקה מתבצעת באמצעות הטוקן הזמני הראשי שהתקבל בהתחברות הראשונית.
    // מוודאים שהטוקן הזמני הראשי קיים לפני מחיקה.
    if (isset($mainTemporaryToken) && !empty($mainTemporaryToken) && isset($apiDomain) && !empty($apiDomain)) {
         deleteFile($apiDomain, $mainTemporaryToken); // קריאה לפונקציית מחיקה עם הטוקן הזמני
         unset($mainTemporaryToken); // מנקה את הטוקן הזמני הראשי מהזיכרון
    } else {
         logLine("⚠️ דילוג על מחיקת קובץ מקור כי הטוקן הזמני הראשי או דומיין ה-API לא היו זמינים.");
    }

    // מנקה את שם המשתמש הראשי מהזיכרון בסוף
    if (isset($mainUsername)) unset($mainUsername);

    logLine("🎉 התהליך הסתיים לחלוטין.");
}
