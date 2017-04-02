; ***** Util functions ***** ;

(defn partial [f & args]
  (let [args-stored (.slice args)]
    (fn [& args-new]
      (let [args-all (.concat [] args-stored args-new)]
        (apply f args-all)))))

; http://stackoverflow.com/a/18116302/2131094
(defn query-serializer [obj]
  (.join (.reduce (.keys Object obj)
                  (fn [a k]
                    (.push a (+ k "=" (encodeURIComponent (get obj k))))
                    a)
                  [])
         "&"))

(defn form-url-encoded [xhr]
  (xhr.setRequestHeader "Content-type" "application/x-www-form-urlencoded"))

(defn case-insensitive-sort [l]
  (let [copy (.slice l)]
    (.sort copy (fn [a b] (.localeCompare (a.toLowerCase) (b.toLowerCase))))
    copy))

(defn handle-error [data error]
  (console.log "Request error:" error)
  (set! (aget data :spinner) false)
  (set! (aget data :error) (get error :error)))

(defn get-current-timestamp []
  (let [tz-offset (* (.getTimezoneOffset (Date.)) 60000)
        now-offset (Date. (- (Date.now) tz-offset))]
    (get (.split (.replace (.toISOString now-offset) "T" " ") ".") 0)))

(defn now []
  (.getTime (Date.)))

(defn launch-focus-checker [data last-fired]
  (let [last-fired-current (or last-fired (now))]
    (if (> (- (now) last-fired-current) 1000)
      (do
        (set! (aget data :timestamp) (get-current-timestamp))
        (m.redraw)))
    (setTimeout (partial launch-focus-checker data (now)) 500)))

(defn log-event [data event-type]
  (let [timestamp (get data :timestamp)
        event-comment (get data :comment)
        send-data {:timestamp timestamp
                   :event event-type}
        request (m.request {:method "POST"
                            :url "server/index.php"
                            :data (if event-comment (do (set! (aget send-data :comment) event-comment) send-data) send-data)
                            :serialize query-serializer
                            :config form-url-encoded
                            :withCredentials true})]
    (set! (aget data :spinner) true)
    (set! (aget data :error) nil)
    (request.then
      (fn [response-data]
        (console.log "Server response:" response-data)
        (set! (aget data :spinner) false)
        (set! (aget data :error) nil)
        (set! (aget data :comment) "")
        (set! (aget data :success-message) "Event logged.")))
    (request.catch (partial handle-error data))))

(defn update-comment [data ev]
  (set! (aget data :comment) (str ev.target.value))
  (set! (aget ev :redraw) false))

(defn update-timestamp [data ev]
  (set! (aget data :timestamp) (str ev.target.value))
  (set! (aget ev :redraw) false))

(defn add-new-event [data ev]
  (let [event-name (get data :event-name)
        send-data {:event event-name
                   :create true}
        request (m.request {:method "POST"
                            :url "server/index.php"
                            :data send-data
                            :serialize query-serializer
                            :config form-url-encoded
                            :withCredentials true})]
    (set! (aget data :spinner) true)
    (set! (aget data :error) nil)
    (request.then
      (fn [response-data]
        (console.log "Server response:" response-data)
        (set! (aget data :spinner) false)
        (set! (aget data :error) nil)
        (set! (aget data :event-name) "")
        (let [events (aget data :event-types)]
          (.push events event-name)
          (set! (aget data :event-types) (case-insensitive-sort events)))
        (set! (aget data :menu-show) false)
        (set! (aget data :success-message) "Event type added.")))
    (request.catch (partial handle-error data))))

(defn update-event-name [data ev]
  (set! (aget data :event-name) (str ev.target.value))
  (set! (aget ev :redraw) false))

(defn reset-success-message [data]
  (setTimeout
    (fn []
      (set! (aget data :success-message) nil)
      (m.redraw))
    2000))

; ***** Components ***** ;

(defn svg-icon [i c]
  (m :svg {:viewBox "0 0 24 24"
           :width 48
           :height 48
           :class c}
     (m :use {:xlink:href (str "#icon-" i)})))

(defn component-spinner [data]
  (m :div {:id "notifications"}
     (if (or (get data :error) (get data :spinner))
       [(if (get data :spinner) (m :div {:id "spinner"} "."))
        (if (get data :error) (m :span {:id "error-messages"} (get data :error)))])))

(defn component-comment [data]
  (m :textarea {:id "comment"
                :rows 2
                :onchange (partial update-comment data)
                :placeholder "Event comment..."} (get data :comment)))

(defn component-timestamp [data]
  (set! (aget data :timestamp) (get-current-timestamp))
  (m :div {:id "timestamp"}
     [(m :button {:id "add-event"
                  :onclick (fn [ev] (m.redraw))} (svg-icon "reload"))
      (m :input {:value (get data :timestamp)
                 :onchange (partial update-timestamp data)})]))

(defn component-events [data]
  (m :div {:id "events"}
     (.map (get data :event-types)
           (fn [event-type i]
             (m :div {:class "event"}
                [(m :span (m :a {:href (str "data/" event-type ".csv")} (str event-type)))
                 (m :button {:class (str "color-" (+ (mod i 5) 1))
                              :onclick (partial log-event data event-type)} (svg-icon "check"))])))))

(defn component-add-new-type [data]
  (m :div {} [(m :button {:id "add-event"
                          :onclick (partial add-new-event data)} "+")
              (m :span {:class "burger-menu-item"}
                 (m :input {:id "add-event-name"
                            :onchange (partial update-event-name data)
                            :placeholder "New event name..."}))]))

(defn component-csv-downloads [data]
  [(m :h3 "CSV downloads")
   (m :div {:class "event"}
      (m :a {:href "server/index.php?events-csv" :target "_new"}
         [(m :button {} "⬇")
          (m :span {:class "burger-menu-item"} "All events combined")]))
   (.map (get data :event-types)
         (fn [event-type i]
           (m :div {:class "event"}
              (m :a {:href (str "data/" event-type ".csv") :class "burger-menu-item" :target "_new"}
                 [(m :button {:class (str "color-" (+ (mod i 5) 1))} "⬇")
                  (m :span event-type)]))))])

(defn component-burger-menu [data]
  (m :div {:id "burger-menu"}
     [(m :div {:id "menu-button"
               :onclick (fn [ev] (set! (aget data :menu-show) (not (get data :menu-show))))}
         (if (get data :menu-show)
           (m :div {:id "settings-back"} "⬅")
           (svg-icon "settings")))
      (if (get data :menu-show)
        (m :div {:id "burger-menu-items"}
           [(m :h3 "Settings & Downloads")
            (m {:view (partial component-add-new-type data)})
            (if (.-length (get data :event-types))
              (component-csv-downloads data))]))]))

(defn component-success-message [data]
  (if (get data :success-message)
    (do
      (reset-success-message data)
      (m :div {:id "success-modal"}
         [(svg-icon "check") (get data :success-message)]))))

(defn component-app [data]
  (m :div [(m {:view (partial component-burger-menu data)})
           (m {:view (partial component-spinner data)})
           (if (not (get data :menu-show))
             ( if (> (.-length (get data :event-types)) 0)
               [(m {:view (partial component-timestamp data)})
                (m {:view (partial component-comment data)})
                (m {:view (partial component-events data)})]
               (m :div {:id "message"} "To get started, add a new event-type in settings.")))
           (m {:view (partial component-success-message data)})]))

; ***** Main ***** ;

(let [app-el (document.getElementById "app")
      request (m.request {:method "GET"
                          :url "server/index.php"
                          :withCredentials true})
      app-data {}]
  (request.then
    (fn [request-data]
      (set! (aget app-data :event-types) (case-insensitive-sort request-data))
      (console.log "Initial app data:" app-data)
      (m.mount app-el {:view (partial component-app app-data)})))
  (request.catch
    (fn [error]
      (m.render app-el (m "div" {:id "loader-error"} "Error connecting to the server."))))
  (launch-focus-checker app-data))

