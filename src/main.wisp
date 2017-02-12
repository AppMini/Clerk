; ***** Util functions ***** ;

(defn partial [f & args]
  (let [args-stored (.slice args 0)]
    ;(console.log "partial outer args-stored" args-stored)
    (fn [& args-new]
      ;(console.log "partial args-new" args-new)
      ;(console.log "partial args-stored" args-stored)
      (let [args-all (.concat [] args-stored args-new)]
        ;(console.log "partial" args-all)
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

(defn log-event [data event-type]
  (let [timestamp (get (.split (.replace (.toISOString (Date.)) "T" " ") ".") 0)
        event-comment (get (get data :app) :comment)
        send-data {:timestamp timestamp
                   :event event-type}
        request (m.request {:method "POST"
                            :url "server/index.php"
                            :data (if event-comment (do (set! (aget send-data :comment) event-comment) send-data) send-data)
                            :serialize query-serializer
                            :config form-url-encoded
                            :withCredentials true})]
    (request.then
      (fn [response-data]
        (console.log response-data)
        (set! (aget (aget data :app) :comment) "")))))

(defn update-comment [data ev]
  (set! (aget (aget data :app) :comment) (str ev.target.value))
  (set! (aget ev :redraw) false))

; ***** Components ***** ;

(defn component-comment [data]
  (m :textarea {:id "comment"
                :rows 1
                :onchange (partial update-comment data)
                :placeholder "Event comment..."} (get (get data :app) :comment)))

(defn component-events [data]
  ;(console.log "component-events" data)
  (m :div {:id "events"}
     (.map (get (get data :app) :event-types)
           (fn [event-type i]
             (m "div" {:class "event"}
                [(m "span" (str event-type))
                 (m "button" {:class (str "color-" (+ (mod i 5) 1))
                              :onclick (partial log-event data event-type)} "✔")])))))

(def component-add-new-type
  (m :div {} [(m :button {:id "add-event"} "+")
              (m :input {:id "add-event-name"
                         :placeholder "New event name..."})]))

(defn component-app [data]
  ;(console.log "component-app" data)
  (m :div [(m {:view (partial component-comment data)})
           (m {:view (partial component-events data)})
           component-add-new-type]))

; ***** Main ***** ;

(let [app-el (document.getElementById "app")
      request (m.request {:method "GET"
                          :url "server/index.php"
                          :data {}
                          :withCredentials true})
      app-data {:app nil}]
  (request.then
    (fn [data]
      (set! (aget app-data :app) {:event-types data})
      (console.log "app-data" app-data)
      (m.mount app-el {:view (partial component-app app-data)})))) ; (partial component-app data)

