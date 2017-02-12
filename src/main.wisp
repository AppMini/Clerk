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

(defn log-event [data event-type]
  (let [timestamp (get (.split (.replace (.toISOString (Date.)) "T" " ") ".") 0)
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
        (set! (aget data :comment) "")))
    (request.catch (partial handle-error data))))

(defn update-comment [data ev]
  (set! (aget data :comment) (str ev.target.value))
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
        (set! (aget data :menu-show) false)))
    (request.catch (partial handle-error data))))

(defn update-event-name [data ev]
  (set! (aget data :event-name) (str ev.target.value))
  (set! (aget ev :redraw) false))

; ***** Components ***** ;

(defn component-spinner [data]
  (m :div {:id "notifications"}
     (if (or (get data :error) (get data :spinner))
       [(if (get data :spinner) (m :div {:id "spinner"} "."))
        (if (get data :error) (m :span {:id "error-messages"} (get data :error)))])))

(defn component-comment [data]
  (m :textarea {:id "comment"
                :rows 1
                :onchange (partial update-comment data)
                :placeholder "Event comment..."} (get data :comment)))

(defn component-events [data]
  (m :div {:id "events"}
     (.map (get data :event-types)
           (fn [event-type i]
             (m "div" {:class "event"}
                [(m "span" (str event-type))
                 (m "button" {:class (str "color-" (+ (mod i 5) 1))
                              :onclick (partial log-event data event-type)} "✔")])))))

(defn component-add-new-type [data]
  (m :div {} [(m :button {:id "add-event"
                          :onclick (partial add-new-event data)} "+")
              (m :input {:id "add-event-name"
                         :onchange (partial update-event-name data)
                         :placeholder "New event name..."})]))

(defn component-burger-menu [data]
  (m :div {:id "burger-menu"}
     [(m :div {:id "menu-button"
               :onclick (fn [ev] (set! (aget data :menu-show) (not (get data :menu-show))))} "☰")
      (if (get data :menu-show)
        (m {:view (partial component-add-new-type data)}))]))

(defn component-app [data]
  (m :div [(m {:view (partial component-burger-menu data)})
           (m {:view (partial component-spinner data)})
           (if (not (get data :menu-show))
             ( if (> (.-length (get data :event-types)) 0)
               [(m {:view (partial component-comment data)})
                (m {:view (partial component-events data)})]
               (m :div {:id "message"} "Add an event-type from the menu to get started.")))]))

; ***** Main ***** ;

(let [app-el (document.getElementById "app")
      request (m.request {:method "GET"
                          :url "server/index.php"
                          :data {}
                          :withCredentials true})
      app-data {}]
  (request.then
    (fn [request-data]
      (set! (aget app-data :event-types) (case-insensitive-sort request-data))
      (console.log "Initial app data:" app-data)
      (m.mount app-el {:view (partial component-app app-data)})))
  (request.catch
    (fn [error]
      (m.render app-el (m "div" {:id "loader-error"} "Error connecting to the server.")))))

