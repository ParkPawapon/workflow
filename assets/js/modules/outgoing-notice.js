(function () {
  var root = document.querySelector("[data-outgoing-notice]");
  if (!root) {
    return;
  }
  var loadingApi = window.App && window.App.loading ? window.App.loading : null;
  var ajaxTargetSelector =
    root.getAttribute("data-ajax-target") || ".table-circular-notice-index";
  var getCircularListLoadingTarget = function () {
    return root.querySelector(ajaxTargetSelector) || root;
  };

  var filterForm = document.getElementById("circularFilterForm");
  var ajaxFilterEnabled =
    !!filterForm &&
    root.getAttribute("data-ajax-filter") === "true" &&
    typeof window.fetch === "function" &&
    typeof window.DOMParser === "function";
  var filterRequestInFlight = false;
  var filterRequestToken = 0;
  var pendingFilterRequest = null;

  var buildFilterUrl = function () {
    if (!filterForm) {
      return "";
    }

    var formData = new FormData(filterForm);
    var params = new URLSearchParams();

    formData.forEach(function (value, key) {
      params.set(key, String(value));
    });

    if (filterForm.id) {
      document
        .querySelectorAll('[form="' + filterForm.id + '"][name]')
        .forEach(function (control) {
          if (!control || control.disabled) {
            return;
          }

          var name = control.getAttribute("name") || "";
          if (name === "") {
            return;
          }

          var type = String(control.type || "").toLowerCase();
          if ((type === "checkbox" || type === "radio") && !control.checked) {
            return;
          }

          if (type === "file") {
            return;
          }

          params.set(name, String(control.value || ""));
        });
    }

    var action = filterForm.getAttribute("action") || "";
    var baseUrl = action !== "" ? action : window.location.pathname;
    var query = params.toString();

    return query === "" ? baseUrl : baseUrl + "?" + query;
  };

  var bindCheckAllToggle = function () {
    var checkAll = document.getElementById("checkAllCircular");
    if (!checkAll) {
      return;
    }

    checkAll.addEventListener("change", function () {
      root
        .querySelectorAll(".check-table:not(.checkall)")
        .forEach(function (checkbox) {
          checkbox.checked = checkAll.checked;
        });
    });
  };

  var applyAjaxFilterUpdate = function (htmlText, requestUrl) {
    var parser = new DOMParser();
    var nextDocument = parser.parseFromString(htmlText, "text/html");
    var currentBulkForm = document.getElementById("bulkActionForm");
    var nextBulkForm = nextDocument.getElementById("bulkActionForm");
    var currentAjaxTarget =
      root.querySelector(ajaxTargetSelector) ||
      document.querySelector(ajaxTargetSelector);
    var nextAjaxTarget = nextDocument.querySelector(ajaxTargetSelector);

    if (currentBulkForm && nextBulkForm) {
      currentBulkForm.replaceWith(nextBulkForm);
    } else if (currentAjaxTarget && nextAjaxTarget) {
      currentAjaxTarget.replaceWith(nextAjaxTarget);
    } else {
      window.location.assign(requestUrl);
      return;
    }

    var currentPagination = document.querySelector(".c-pagination");
    var nextPagination = nextDocument.querySelector(".c-pagination");

    if (currentPagination && nextPagination) {
      currentPagination.replaceWith(nextPagination);
    } else if (!currentPagination && nextPagination && root.parentNode) {
      root.insertAdjacentElement("afterend", nextPagination);
    } else if (currentPagination && !nextPagination) {
      currentPagination.remove();
    }

    var currentActionBar = document.querySelector(".button-circular-notice-keep");
    var nextActionBar = nextDocument.querySelector(".button-circular-notice-keep");

    if (currentActionBar && nextActionBar) {
      currentActionBar.replaceWith(nextActionBar);
    } else if (!currentActionBar && nextActionBar && root.parentNode) {
      root.insertAdjacentElement("afterend", nextActionBar);
    } else if (currentActionBar && !nextActionBar) {
      currentActionBar.remove();
    }

    window.history.replaceState({}, "", requestUrl);
    syncActiveTableViewFromUrl(requestUrl);
    bindCheckAllToggle();
  };

  var submitFilter = function (options) {
    options = options || {};

    if (!filterForm) {
      return;
    }

    var targetUrl = options.requestUrl || buildFilterUrl();

    if (!ajaxFilterEnabled || targetUrl === "") {
      filterForm.submit();
      return;
    }

    if (filterRequestInFlight) {
      pendingFilterRequest = {
        requestUrl: targetUrl,
      };
      return;
    }

    filterRequestInFlight = true;
    filterRequestToken += 1;
    var currentToken = filterRequestToken;

    if (loadingApi) {
      loadingApi.startComponent(getCircularListLoadingTarget());
    }

    window
      .fetch(targetUrl, {
        headers: {
          "X-Requested-With": "XMLHttpRequest",
        },
        credentials: "same-origin",
      })
      .then(function (response) {
        if (!response.ok) {
          throw new Error("Failed to fetch filtered list");
        }
        return response.text();
      })
      .then(function (htmlText) {
        if (currentToken !== filterRequestToken) {
          return;
        }
        applyAjaxFilterUpdate(htmlText, targetUrl);
      })
      .catch(function () {
        window.location.assign(targetUrl);
      })
      .finally(function () {
        if (loadingApi) {
          loadingApi.stopComponent(getCircularListLoadingTarget());
        }

        if (currentToken === filterRequestToken) {
          filterRequestInFlight = false;
        }

        if (pendingFilterRequest !== null) {
          var nextRequest = pendingFilterRequest;
          pendingFilterRequest = null;
          submitFilter(nextRequest);
        }
      });
  };

  var requestFilterUpdate = function (options) {
    if (ajaxFilterEnabled) {
      submitFilter(options);
      return;
    }

    if (filterForm) {
      filterForm.submit();
    }
  };

  var setActiveTableView = function (view) {
    var selectedView = view || "table1";
    document
      .querySelectorAll(".table-change button[data-view]")
      .forEach(function (button) {
        button.classList.toggle(
          "active",
          (button.getAttribute("data-view") || "table1") === selectedView,
        );
      });
  };

  var syncActiveTableViewFromUrl = function (requestUrl) {
    try {
      var url = new URL(requestUrl, window.location.href);
      setActiveTableView(url.searchParams.get("view") || "table1");
    } catch (error) {
      var viewInput = document.getElementById("filterViewInput");
      setActiveTableView(viewInput ? viewInput.value : "table1");
    }
  };

  if (filterForm) {
    if (ajaxFilterEnabled) {
      filterForm.addEventListener("submit", function (event) {
        event.preventDefault();
        submitFilter();
      });
    }

    document.querySelectorAll(".custom-select-wrapper").forEach(function (wrapper) {
      var targetId = wrapper.getAttribute("data-target") || "";
      var input = targetId ? document.getElementById(targetId) : null;
      var nativeSelect = wrapper.querySelector("select");
      var options = wrapper.querySelectorAll(".custom-option");
      var valueDisplay = wrapper.querySelector(".select-value");

      options.forEach(function (option) {
        option.addEventListener("click", function () {
          var value = option.getAttribute("data-value") || "";
          if (input) {
            input.value = value;
          }
          if (nativeSelect) {
            nativeSelect.value = value;
          }
          if (valueDisplay) {
            valueDisplay.textContent = option.textContent.trim();
          }
          if (input) {
            requestFilterUpdate();
          }
        });
      });
    });

    var typeCheckboxes = document.querySelectorAll("[data-filter-type]");
    if (typeCheckboxes.length) {
      var filterTypeInput = document.getElementById("filterTypeInput");
      var updateTypeFilter = function () {
        if (!filterTypeInput) return;
        var checked = Array.prototype.slice
          .call(typeCheckboxes)
          .filter(function (checkbox) {
            return checkbox.checked;
          })
          .map(function (checkbox) {
            return checkbox.value;
          });
        var value = "all";
        if (checked.length === 1) {
          value = checked[0] || "all";
        }
        filterTypeInput.value = value;
        requestFilterUpdate();
      };

      typeCheckboxes.forEach(function (checkbox) {
        checkbox.addEventListener("change", updateTypeFilter);
      });
    }

    var readCheckboxes = document.querySelectorAll("[data-filter-read]");
    if (readCheckboxes.length) {
      var filterReadInput = document.getElementById("filterReadInput");
      var updateReadFilter = function () {
        if (!filterReadInput) return;
        var checked = Array.prototype.slice
          .call(readCheckboxes)
          .filter(function (checkbox) {
            return checkbox.checked;
          })
          .map(function (checkbox) {
            return checkbox.value;
          });
        var value = "all";
        if (checked.length === 1) {
          value = checked[0] || "all";
        }
        filterReadInput.value = value;
        requestFilterUpdate();
      };

      readCheckboxes.forEach(function (checkbox) {
        checkbox.addEventListener("change", updateReadFilter);
      });
    }

    var searchInput = document.getElementById("search-input");
    if (searchInput) {
      var autoSubmit = searchInput.getAttribute("data-auto-submit") === "true";
      var autoSubmitDelay = parseInt(searchInput.getAttribute("data-auto-submit-delay") || "450", 10);
      var searchTimer = null;
      var isComposing = false;
      var submitSearch = function () {
        if (searchTimer) {
          window.clearTimeout(searchTimer);
          searchTimer = null;
        }
        requestFilterUpdate();
      };

      searchInput.addEventListener("keydown", function (event) {
        if (event.key === "Enter") {
          event.preventDefault();
          submitSearch();
        }
      });

      if (autoSubmit) {
        searchInput.addEventListener("compositionstart", function () {
          isComposing = true;
        });

        searchInput.addEventListener("compositionend", function () {
          isComposing = false;
          if (searchTimer) {
            window.clearTimeout(searchTimer);
          }
          searchTimer = window.setTimeout(submitSearch, autoSubmitDelay);
        });

        searchInput.addEventListener("input", function () {
          if (isComposing) {
            return;
          }
          if (searchTimer) {
            window.clearTimeout(searchTimer);
          }
          searchTimer = window.setTimeout(submitSearch, autoSubmitDelay);
        });
      }
    }

    document.querySelectorAll(".table-change button[data-view]").forEach(function (button) {
      button.addEventListener("click", function () {
        var viewInput = document.getElementById("filterViewInput");
        var nextView = button.getAttribute("data-view") || "table1";
        if (viewInput) {
          viewInput.value = nextView;
        }
        setActiveTableView(nextView);
        requestFilterUpdate();
      });
    });
  }
  bindCheckAllToggle();

  var modalOverlay = document.getElementById("modalNoticeKeepOverlay");
  var modalClose = document.getElementById("closeModalNoticeKeep");
  var fileSection = document.getElementById("modalFileSection");
  var modalLink =
    document.getElementById("modalLink") ||
    document.getElementById("edit_linkURL");
  var modalArchiveId = document.getElementById("modalInboxId");
  var modalTypeLabel = document.getElementById("modalTypeLabel");
  var modalSubject =
    document.getElementById("modalSubject") ||
    document.getElementById("edit_subject");
  var modalSender =
    document.getElementById("modalSender") ||
    document.getElementById("edit_senderDisplay");
  var modalSenderFaction = document.getElementById("edit_fromFIDDisplay");
  var modalDate = document.getElementById("modalDate");
  var modalDetail =
    document.getElementById("modalDetail") ||
    document.getElementById("edit_detail");
  var receiptStatusTableBody = document.getElementById("receiptStatusTableBody");

  var modalUrgency = document.getElementById("modalUrgency");
  var modalBookNo = document.getElementById("modalBookNo");
  var modalIssuedDate = document.getElementById("modalIssuedDate");
  var modalFromText = document.getElementById("modalFromText");
  var modalToText = document.getElementById("modalToText");
  var modalStatus = document.getElementById("modalStatus");
  var modalReceivedTime = document.getElementById("modalReceivedTime");
  var modalConsiderStatus = document.getElementById("modalConsiderStatus");
  var noticeViewBookNo = document.getElementById("noticeOutgoingViewBookNo");
  var noticeViewIssuedDate = document.getElementById("noticeOutgoingViewIssuedDate");
  var noticeViewSubject = document.getElementById("noticeOutgoingViewSubjectText");
  var noticeViewFrom = document.getElementById("noticeOutgoingViewFrom");
  var noticeViewGroup = document.getElementById("noticeOutgoingViewGroup");
  var noticeViewLink = document.getElementById("noticeOutgoingViewLink");
  var noticeViewProposer = document.getElementById("noticeOutgoingViewProposer");
  var noticeViewMemoSection = document.getElementById(
    "noticeOutgoingViewDetailSection",
  );
  var noticeViewDirectorComment = document.getElementById(
    "noticeOutgoingDirectorComment",
  );
  var noticeViewDirectorCommentSection = document.getElementById(
    "noticeOutgoingDirectorCommentSection",
  );
  var noticeViewLatestCommentLabel = document.getElementById(
    "noticeOutgoingLatestCommentLabel",
  );
  var noticeViewRegistryComment = document.getElementById(
    "noticeOutgoingRegistryComment",
  );
  var noticeViewRegistryCommentSection = document.getElementById(
    "noticeOutgoingRegistryCommentSection",
  );
  var noticeViewReviewComment = document.getElementById(
    "noticeOutgoingReviewComment",
  );
  var noticeViewReviewCommentSection = document.getElementById(
    "noticeOutgoingReviewCommentSection",
  );
  var noticeViewReviewCommentLabel = document.getElementById(
    "noticeOutgoingReviewCommentLabel",
  );
  var noticeViewCoverSection = document.getElementById("noticeOutgoingViewCoverSection");
  var noticeViewCoverList = document.getElementById("noticeOutgoingViewCoverList");
  var noticeViewAttachmentSection = document.getElementById("noticeOutgoingViewAttachmentSection");
  var noticeViewAttachmentList = document.getElementById("noticeOutgoingViewAttachmentList");
  var noticeViewUrgencyRadios = modalOverlay
    ? Array.prototype.slice.call(
        modalOverlay.querySelectorAll("[data-notice-view-urgent]"),
      )
    : [];
  var csrfTokenEl = document.getElementById("csrfToken");
  var csrfToken = csrfTokenEl ? csrfTokenEl.value : "";

  function buildFileItem(file, entityId) {
    var container = document.createElement("div");
    container.className = "file-banner";

    var info = document.createElement("div");
    info.className = "file-info";

    var iconWrap = document.createElement("div");
    iconWrap.className = "file-icon";
    var icon = document.createElement("i");
    var mime = (file.mimeType || "").toLowerCase();
    if (mime.indexOf("pdf") >= 0) {
      icon.className = "fa-solid fa-file-pdf";
    } else if (mime.indexOf("image") >= 0) {
      icon.className = "fa-solid fa-file-image";
    } else {
      icon.className = "fa-solid fa-file";
    }
    iconWrap.appendChild(icon);

    var text = document.createElement("div");
    text.className = "file-text";
    var nameEl = document.createElement("span");
    nameEl.className = "file-name";
    nameEl.textContent = file.fileName || "-";
    var typeEl = document.createElement("span");
    typeEl.className = "file-type";
    typeEl.textContent = file.mimeType || "";
    text.appendChild(nameEl);
    text.appendChild(typeEl);

    info.appendChild(iconWrap);
    info.appendChild(text);

    var viewAction = document.createElement("div");
    viewAction.className = "file-actions";
    var viewLink = document.createElement("a");
    viewLink.href =
      "public/api/file-download.php?module=circulars&entity_id=" +
      encodeURIComponent(entityId) +
      "&file_id=" +
      encodeURIComponent(file.fileID || "");
    viewLink.target = "_blank";
    viewLink.rel = "noopener";
    viewLink.innerHTML = '<i class="fa-solid fa-eye"></i>';
    viewAction.appendChild(viewLink);

    var downloadAction = document.createElement("div");
    downloadAction.className = "file-actions";
    var downloadLink = document.createElement("a");
    downloadLink.href =
      "public/api/file-download.php?module=circulars&entity_id=" +
      encodeURIComponent(entityId) +
      "&file_id=" +
      encodeURIComponent(file.fileID || "") +
      "&download=1";
    downloadLink.innerHTML = '<i class="fa-solid fa-download"></i>';
    downloadAction.appendChild(downloadLink);

    container.appendChild(info);
    container.appendChild(viewAction);
    container.appendChild(downloadAction);

    return container;
  }

  function renderFiles(files, entityId) {
    if (!fileSection) return;
    var fileSectionWrapper = fileSection.closest(".content-file-sec");
    fileSection.innerHTML = "";
    if (!files || files.length === 0) {
      if (fileSectionWrapper) {
        fileSectionWrapper.style.display = "none";
      }
      return;
    }
    if (fileSectionWrapper) {
      fileSectionWrapper.style.display = "";
    }
    files.forEach(function (file) {
      fileSection.appendChild(buildFileItem(file, entityId));
    });
  }

  function setModalFieldValue(field, value) {
    if (!field) {
      return;
    }

    var normalizedValue = String(value || "").trim();
    if (normalizedValue === "") {
      normalizedValue = "-";
    }

    var tagName = (field.tagName || "").toUpperCase();
    if (tagName === "INPUT" || tagName === "TEXTAREA") {
      field.value = normalizedValue;
      return;
    }

    field.textContent = normalizedValue;
  }

  function setModalLinkValue(field, value) {
    if (!field) {
      return;
    }

    var normalizedValue = String(value || "").trim();
    var displayValue = normalizedValue !== "" ? normalizedValue : "-";
    var tagName = (field.tagName || "").toUpperCase();

    if (tagName === "A") {
      field.textContent = displayValue;
      field.href = normalizedValue !== "" ? normalizedValue : "#";
      return;
    }

    if (tagName === "INPUT" || tagName === "TEXTAREA") {
      field.value = displayValue;
      return;
    }

    field.textContent = displayValue;
  }

  function setNoticeViewFieldValue(field, value) {
    if (!field) {
      return;
    }

    var displayValue = String(value || "").trim() || "-";
    field.value = displayValue;
    field.setAttribute("title", displayValue);
  }

  function setNoticeViewUrgency(value) {
    var selectedValue = String(value || "normal").trim().toLowerCase();
    if (["urgent", "high", "highest"].indexOf(selectedValue) === -1) {
      selectedValue = "normal";
    }

    var matched = false;
    noticeViewUrgencyRadios.forEach(function (radio) {
      var isMatched =
        String(radio.getAttribute("data-notice-view-urgent") || "")
          .trim()
          .toLowerCase() === selectedValue;
      radio.checked = isMatched;
      matched = matched || isMatched;
    });

    if (!matched && noticeViewUrgencyRadios[0]) {
      noticeViewUrgencyRadios[0].checked = true;
    }
  }

  function setNoticeViewEditorContent(value) {
    var normalizedValue = String(value || "").trim() || "<p>-</p>";
    var editor =
      window.tinymce && typeof window.tinymce.get === "function"
        ? window.tinymce.get("notice_memo_editor_view")
        : null;

    if (editor) {
      editor.setContent(normalizedValue);
      return;
    }

    var textarea = document.getElementById("notice_memo_editor_view");
    if (textarea) {
      textarea.value = normalizedValue;
    }

    window.setTimeout(function () {
      var delayedEditor =
        window.tinymce && typeof window.tinymce.get === "function"
          ? window.tinymce.get("notice_memo_editor_view")
          : null;
      if (delayedEditor) {
        delayedEditor.setContent(normalizedValue);
      }
    }, 50);
  }

  function setNoticeViewReadonlyEditorContent(editorId, textarea, value) {
    var normalizedValue = String(value || "").trim() || "<p>-</p>";
    var editor =
      window.tinymce && typeof window.tinymce.get === "function"
        ? window.tinymce.get(editorId)
        : null;

    if (editor) {
      editor.setContent(normalizedValue);
      return;
    }

    if (textarea) {
      textarea.value = normalizedValue;
    }

    window.setTimeout(function () {
      var delayedEditor =
        window.tinymce && typeof window.tinymce.get === "function"
          ? window.tinymce.get(editorId)
          : null;
      if (delayedEditor) {
        delayedEditor.setContent(normalizedValue);
      }
    }, 50);
  }

  function setNoticeViewDirectorCommentContent(value) {
    setNoticeViewReadonlyEditorContent(
      "noticeOutgoingDirectorComment",
      noticeViewDirectorComment,
      value,
    );
  }

  function setNoticeViewRegistryCommentContent(value) {
    setNoticeViewReadonlyEditorContent(
      "noticeOutgoingRegistryComment",
      noticeViewRegistryComment,
      value,
    );
  }

  function setNoticeViewReviewCommentContent(value) {
    setNoticeViewReadonlyEditorContent(
      "noticeOutgoingReviewComment",
      noticeViewReviewComment,
      value,
    );
  }

  function formatFileSize(bytes) {
    var size = Number(bytes || 0);
    if (!Number.isFinite(size) || size <= 0) {
      return "0 KB";
    }
    if (size >= 1024 * 1024) {
      return (size / (1024 * 1024)).toFixed(1) + " MB";
    }
    return Math.max(1, Math.round(size / 1024)) + " KB";
  }

  function isNoticeCoverFile(file) {
    var note = String(
      (file && (file.fileNote || file.note || file.field)) || "",
    )
      .trim()
      .toLowerCase();

    return (
      [
        "cover_file",
        "cover_attachments",
        "cover",
        "lead_file",
        "หนังสือนำ",
      ].indexOf(note) !== -1
    );
  }

  function splitNoticeViewFiles(files) {
    var normalizedFiles = Array.isArray(files) ? files : [];
    var coverFiles = normalizedFiles.filter(function (file) {
      return isNoticeCoverFile(file);
    });
    var attachmentFiles = normalizedFiles.filter(function (file) {
      return !isNoticeCoverFile(file);
    });

    if (coverFiles.length === 0 && normalizedFiles.length > 0) {
      return {
        coverFiles: [normalizedFiles[0]],
        attachmentFiles: normalizedFiles.slice(1),
      };
    }

    return {
      coverFiles: coverFiles,
      attachmentFiles: attachmentFiles,
    };
  }

  function renderNoticeViewFileList(section, list, files, entityId) {
    if (!section || !list) {
      return;
    }

    if (!Array.isArray(files) || files.length === 0) {
      section.style.display = "none";
      list.innerHTML = "";
      return;
    }

    section.style.display = "";
    list.innerHTML = "";
    files.forEach(function (file) {
      var wrapper = document.createElement("div");
      wrapper.className = "file-item-wrapper";

      var banner = document.createElement("div");
      banner.className = "file-banner";

      var info = document.createElement("div");
      info.className = "file-info";

      var iconWrap = document.createElement("div");
      iconWrap.className = "file-icon";
      var icon = document.createElement("i");
      var mime = String((file && file.mimeType) || "").toLowerCase();
      icon.className =
        mime.indexOf("pdf") >= 0
          ? "fa-solid fa-file-pdf"
          : "fa-solid fa-file-image";
      iconWrap.appendChild(icon);

      var text = document.createElement("div");
      text.className = "file-text";
      var nameEl = document.createElement("span");
      nameEl.className = "file-name";
      nameEl.textContent = String((file && file.fileName) || "-").trim() || "-";
      var typeEl = document.createElement("span");
      typeEl.className = "file-type";
      typeEl.textContent =
        (String((file && file.mimeType) || "").trim() || "ไฟล์แนบ") +
        " • " +
        formatFileSize(file && file.fileSize);
      text.appendChild(nameEl);
      text.appendChild(typeEl);

      info.appendChild(iconWrap);
      info.appendChild(text);
      banner.appendChild(info);

      var fileId = String((file && file.fileID) || "").trim();
      if (fileId !== "" && String(entityId || "").trim() !== "") {
        var actions = document.createElement("div");
        actions.className = "file-actions";
        var viewLink = document.createElement("a");
        viewLink.className = "action-btn";
        viewLink.href =
          "public/api/file-download.php?module=circulars&entity_id=" +
          encodeURIComponent(entityId) +
          "&file_id=" +
          encodeURIComponent(fileId);
        viewLink.target = "_blank";
        viewLink.rel = "noopener";
        viewLink.title = "ดูตัวอย่าง";
        viewLink.innerHTML = '<i class="fa-solid fa-eye"></i>';
        actions.appendChild(viewLink);
        banner.appendChild(actions);
      }

      wrapper.appendChild(banner);
      list.appendChild(wrapper);
    });
  }

  function renderNoticeViewFiles(files, entityId) {
    var groupedFiles = splitNoticeViewFiles(files);

    renderNoticeViewFileList(
      noticeViewCoverSection,
      noticeViewCoverList,
      groupedFiles.coverFiles,
      entityId,
    );
    renderNoticeViewFileList(
      noticeViewAttachmentSection,
      noticeViewAttachmentList,
      groupedFiles.attachmentFiles,
      entityId,
    );
  }

  function populateNoticeViewModal(button, entityId, files) {
    if (!noticeViewBookNo) {
      return;
    }

    setNoticeViewUrgency(button.getAttribute("data-urgency-class"));
    setNoticeViewFieldValue(noticeViewBookNo, button.getAttribute("data-bookno"));
    if (noticeViewIssuedDate) {
      var rawDate = String(button.getAttribute("data-issued-raw") || "").trim();
      noticeViewIssuedDate.value = /^\d{4}-\d{2}-\d{2}$/.test(rawDate)
        ? rawDate
        : "";
      noticeViewIssuedDate.setAttribute(
        "title",
        String(button.getAttribute("data-issued") || "").trim() || "-",
      );
    }
    setNoticeViewFieldValue(noticeViewSubject, button.getAttribute("data-subject"));
    setNoticeViewFieldValue(noticeViewFrom, button.getAttribute("data-from"));
    setNoticeViewFieldValue(noticeViewGroup, button.getAttribute("data-group"));
    setNoticeViewFieldValue(noticeViewLink, button.getAttribute("data-link"));
    setNoticeViewFieldValue(
      noticeViewProposer,
      button.getAttribute("data-sender-name") ||
        button.getAttribute("data-sender") ||
        "-",
    );
    setNoticeViewEditorContent(button.getAttribute("data-detail"));
    var latestComment = button.hasAttribute("data-latest-comment")
      ? String(button.getAttribute("data-latest-comment") || "").trim()
      : "";
    var latestCommentLabel =
      String(
        button.getAttribute("data-latest-comment-label") ||
          "ความคิดเห็นของผู้ส่งล่าสุด",
      ).trim() || "ความคิดเห็นของผู้ส่งล่าสุด";
    var registryComment = String(
      button.getAttribute("data-review-chain-registry-comment") || "",
    ).trim();
    var reviewComment = String(
      button.getAttribute("data-review-chain-director-comment") || "",
    ).trim();
    var reviewCommentLabel =
      String(
        button.getAttribute("data-review-chain-director-label") ||
          button.getAttribute("data-director-comment-label") ||
          "ความคิดเห็นของผู้อำนวยการโรงเรียน",
      ).trim() || "ความคิดเห็นของผู้อำนวยการโรงเรียน";
    var shouldSplitReviewComments = registryComment !== "" || reviewComment !== "";
    var shouldHideMemoDetail =
      shouldSplitReviewComments ||
      String(button.getAttribute("data-hide-memo-detail") || "").trim() === "1";

    if (noticeViewMemoSection) {
      noticeViewMemoSection.style.display = shouldHideMemoDetail ? "none" : "";
    }

    if (noticeViewLatestCommentLabel) {
      noticeViewLatestCommentLabel.textContent = latestCommentLabel;
    }

    if (noticeViewDirectorCommentSection) {
      noticeViewDirectorCommentSection.style.display =
        !shouldSplitReviewComments && latestComment !== "" ? "" : "none";
    }

    if (noticeViewRegistryCommentSection) {
      noticeViewRegistryCommentSection.style.display =
        shouldSplitReviewComments && registryComment !== "" ? "" : "none";
    }

    if (noticeViewReviewCommentSection) {
      noticeViewReviewCommentSection.style.display =
        shouldSplitReviewComments && reviewComment !== "" ? "" : "none";
    }

    if (noticeViewReviewCommentLabel) {
      noticeViewReviewCommentLabel.textContent = reviewCommentLabel;
    }

    setNoticeViewDirectorCommentContent(
      shouldSplitReviewComments ? "" : latestComment,
    );
    setNoticeViewRegistryCommentContent(registryComment);
    setNoticeViewReviewCommentContent(reviewComment);
    renderNoticeViewFiles(files, entityId);
  }

  function renderReceiptStatuses(rawStats) {
    if (!receiptStatusTableBody) {
      return;
    }

    var parsedStats = [];
    try {
      parsedStats = rawStats ? JSON.parse(rawStats) : [];
    } catch (error) {
      parsedStats = [];
    }

    if (!Array.isArray(parsedStats) || parsedStats.length === 0) {
      receiptStatusTableBody.innerHTML =
        '<tr><td colspan="3" class="enterprise-empty">ไม่พบข้อมูลผู้รับ</td></tr>';
      return;
    }

    receiptStatusTableBody.innerHTML = "";

    parsedStats.forEach(function (entry) {
      var row = document.createElement("tr");
      var nameCell = document.createElement("td");
      var statusCell = document.createElement("td");
      var readAtCell = document.createElement("td");
      var statusPill = document.createElement("span");
      var isRead = Number(entry && entry.isRead) === 1;

      nameCell.textContent = String((entry && entry.fName) || "-").trim() || "-";
      statusPill.className = "status-pill " + (isRead ? "approved" : "pending");
      statusPill.textContent = isRead ? "อ่านแล้ว" : "ยังไม่อ่าน";
      statusCell.appendChild(statusPill);
      readAtCell.textContent =
        String((entry && entry.readAtDisplay) || "-").trim() || "-";

      row.appendChild(nameCell);
      row.appendChild(statusCell);
      row.appendChild(readAtCell);
      receiptStatusTableBody.appendChild(row);
    });
  }

  function openModal() {
    if (!modalOverlay) return;
    modalOverlay.style.display = "flex";
  }

  function closeModal() {
    if (!modalOverlay) return;
    modalOverlay.style.display = "none";
  }

  function updateRowAsRead(row, button) {
    if (row) {
      var badge = row.querySelector(".status-badge");
      if (badge) {
        badge.classList.remove("unread");
        badge.classList.add("read");
        badge.textContent = "อ่านแล้ว";
      }
    }

    if (button && button.setAttribute) {
      button.setAttribute("data-read-state", "read");
    }
  }

  function shouldRefreshUnreadFilterAfterRead() {
    var filterReadInput = document.getElementById("filterReadInput");
    return !!filterReadInput && String(filterReadInput.value || "") === "unread";
  }

  function parseResponsePayload(text) {
    var normalizedText = String(text || "").trim();

    if (normalizedText === "") {
      return null;
    }

    try {
      return JSON.parse(normalizedText);
    } catch (error) {}

    var jsonStart = normalizedText.indexOf("{");
    var jsonEnd = normalizedText.lastIndexOf("}");

    if (jsonStart === -1 || jsonEnd <= jsonStart) {
      return null;
    }

    try {
      return JSON.parse(normalizedText.slice(jsonStart, jsonEnd + 1));
    } catch (error) {
      return null;
    }
  }

  function markRead(inboxId, row, button) {
    if (!inboxId || !csrfToken) {
      return;
    }

    var rowLoadingTarget =
      (row &&
        (row.closest(".table-circular-notice-index") ||
          row.closest(".table-circular-notice-keep") ||
          row.closest(".table-circular-notice-archive"))) ||
      getCircularListLoadingTarget();

    if (loadingApi) {
      loadingApi.startComponent(rowLoadingTarget);
    }

    fetch("public/api/circular-read.php", {
      method: "POST",
      credentials: "same-origin",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/x-www-form-urlencoded",
        "X-Requested-With": "XMLHttpRequest",
      },
      body:
        "inbox_id=" +
        encodeURIComponent(inboxId) +
        "&csrf_token=" +
        encodeURIComponent(csrfToken),
    })
      .then(function (response) {
        return response.text().then(function (text) {
          return {
            ok: response.ok,
            payload: parseResponsePayload(text),
          };
        });
      })
      .then(function (result) {
        if (!result || result.ok !== true) {
          return;
        }

        if (result.payload && result.payload.success === false) {
          return;
        }

        updateRowAsRead(row, button);

        if (shouldRefreshUnreadFilterAfterRead()) {
          requestFilterUpdate();
        }
      })
      .catch(function () {})
      .finally(function () {
        if (loadingApi) {
          loadingApi.stopComponent(rowLoadingTarget);
        }
      });
  }

  if (modalClose) {
    modalClose.addEventListener("click", closeModal);
  }

  if (modalOverlay) {
    modalOverlay.addEventListener("click", function (event) {
      if (event.target === modalOverlay) {
        closeModal();
      }
    });
  }

  var handleOpenCircularModal = function (button) {
    if (!button) {
      return;
    }

    var entityId = button.getAttribute("data-circular-id") || "";
    var inboxId = button.getAttribute("data-inbox-id") || "";
    var row = button.closest("tr");
    var statusBadge = row ? row.querySelector(".status-badge") : null;
    var shouldMarkRead =
      !!inboxId &&
      !!statusBadge &&
      statusBadge.classList.contains("unread");
    var files = button.getAttribute("data-files");
    var parsedFiles = [];
    try {
      parsedFiles = files ? JSON.parse(files) : [];
    } catch (e) {
      parsedFiles = [];
    }

    if (modalTypeLabel) {
      modalTypeLabel.textContent =
        button.getAttribute("data-modal-title") ||
        button.getAttribute("data-type") ||
        "ประเภทหนังสือ";
    }
    setModalFieldValue(modalSubject, button.getAttribute("data-subject") || "-");
    setModalFieldValue(
      modalSender,
      button.getAttribute("data-sender-name") ||
        button.getAttribute("data-sender") ||
        "-"
    );
    setModalFieldValue(
      modalSenderFaction,
      button.getAttribute("data-sender-faction") || "-"
    );
    if (modalDate) {
      modalDate.textContent = button.getAttribute("data-date") || "-";
    }
    setModalFieldValue(modalDetail, button.getAttribute("data-detail") || "-");
    setModalLinkValue(modalLink, button.getAttribute("data-link") || "");
    if (modalArchiveId) {
      modalArchiveId.value = inboxId;
    }

    if (modalUrgency) {
      var urgency = button.getAttribute("data-urgency") || "ปกติ";
      var urgencyClass =
        button.getAttribute("data-urgency-class") || "normal";
      modalUrgency.className =
        ("urgency-status " + urgencyClass).trim();
      var urgencyText = modalUrgency.querySelector("p");
      if (urgencyText) {
        urgencyText.textContent = urgency;
      }
    }
    if (modalBookNo) {
      modalBookNo.value = button.getAttribute("data-bookno") || "-";
    }
    if (modalIssuedDate) {
      modalIssuedDate.value = button.getAttribute("data-issued") || "-";
    }
    if (modalFromText) {
      modalFromText.value = button.getAttribute("data-from") || "-";
    }
    if (modalToText) {
      modalToText.value = button.getAttribute("data-to") || "-";
    }
    if (modalStatus) {
      modalStatus.value = button.getAttribute("data-status") || "-";
    }
    if (modalReceivedTime) {
      modalReceivedTime.value =
        button.getAttribute("data-received-time") || "-";
    }
    if (modalConsiderStatus) {
      var statusClass = button.getAttribute("data-consider") || "considering";
      modalConsiderStatus.className =
        ("consider-status " + statusClass).trim();
      modalConsiderStatus.textContent =
        button.getAttribute("data-status") || "กำลังเสนอ";
    }

    renderFiles(parsedFiles, entityId);
    populateNoticeViewModal(button, entityId, parsedFiles);
    renderReceiptStatuses(button.getAttribute("data-read-stats") || "[]");
    openModal();
    if (shouldMarkRead) {
      markRead(inboxId, row, button);
    }
  };

  root.addEventListener("click", function (event) {
    var button = event.target.closest(".js-open-circular-modal");

    if (!button || !root.contains(button)) {
      return;
    }

    handleOpenCircularModal(button);
  });

  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape") {
      closeModal();
    }
  });
})();
