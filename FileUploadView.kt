package com.smartapp.ui.components

import android.content.Context
import android.net.Uri
import android.util.AttributeSet
import android.view.LayoutInflater
import android.widget.FrameLayout
import androidx.activity.result.ActivityResultLauncher
import androidx.core.view.isVisible
import com.bumptech.glide.Glide
import com.smartapp.R
import com.smartapp.databinding.ViewFileUploadBinding
import com.smartapp.util.Constants
import com.smartapp.util.formatFileSize
import com.smartapp.util.getMimeType
import com.smartapp.util.hide
import com.smartapp.util.show
import java.io.File

class FileUploadView @JvmOverloads constructor(
    context: Context,
    attrs: AttributeSet? = null,
    defStyleAttr: Int = 0
) : FrameLayout(context, attrs, defStyleAttr) {

    private val binding: ViewFileUploadBinding
    private var maxFileSize: Int = Constants.Validation.MAX_FILE_SIZE
    private var allowedFileTypes: List<String> = listOf()
    private var onFileSelected: ((File) -> Unit)? = null
    private var onFileRemoved: (() -> Unit)? = null
    private var filePicker: ActivityResultLauncher<String>? = null

    init {
        binding = ViewFileUploadBinding.inflate(LayoutInflater.from(context), this, true)

        attrs?.let {
            val typedArray = context.obtainStyledAttributes(it, R.styleable.FileUploadView)
            try {
                maxFileSize = typedArray.getInteger(
                    R.styleable.FileUploadView_maxFileSize,
                    Constants.Validation.MAX_FILE_SIZE
                )
                typedArray.getString(R.styleable.FileUploadView_allowedFileTypes)?.let { types ->
                    allowedFileTypes = types.split(",").map { it.trim() }
                }
                binding.btnUpload.text = typedArray.getString(
                    R.styleable.FileUploadView_uploadButtonText
                ) ?: context.getString(R.string.action_upload)

                val showFileTypeIcon = typedArray.getBoolean(
                    R.styleable.FileUploadView_showFileTypeIcon,
                    true
                )
                val showFileSize = typedArray.getBoolean(
                    R.styleable.FileUploadView_showFileSize,
                    true
                )
                binding.ivFileType.isVisible = showFileTypeIcon
                binding.tvFileSize.isVisible = showFileSize
            } finally {
                typedArray.recycle()
            }
        }

        setupViews()
    }

    private fun setupViews() {
        binding.btnUpload.setOnClickListener {
            filePicker?.launch("*/*")
        }

        binding.btnRemove.setOnClickListener {
            clearFile()
            onFileRemoved?.invoke()
        }
    }

    fun setFilePicker(picker: ActivityResultLauncher<String>) {
        filePicker = picker
    }

    fun setOnFileSelectedListener(listener: (File) -> Unit) {
        onFileSelected = listener
    }

    fun setOnFileRemovedListener(listener: () -> Unit) {
        onFileRemoved = listener
    }

    fun handleFileResult(uri: Uri?) {
        uri?.let { fileUri ->
            context.contentResolver.openInputStream(fileUri)?.use { inputStream ->
                // Create temporary file
                val file = File(context.cacheDir, "upload_${System.currentTimeMillis()}")
                file.outputStream().use { outputStream ->
                    inputStream.copyTo(outputStream)
                }

                // Validate file
                when {
                    file.length() > maxFileSize -> {
                        showError(context.getString(R.string.msg_file_too_large))
                        file.delete()
                    }
                    allowedFileTypes.isNotEmpty() && !allowedFileTypes.contains(file.extension) -> {
                        showError(context.getString(R.string.msg_invalid_file_type))
                        file.delete()
                    }
                    else -> {
                        showFile(file)
                        onFileSelected?.invoke(file)
                    }
                }
            }
        }
    }

    private fun showFile(file: File) {
        binding.apply {
            groupUpload.hide()
            groupFileInfo.show()

            // Set file name
            tvFileName.text = file.name

            // Set file size
            if (tvFileSize.isVisible) {
                tvFileSize.text = file.length().formatFileSize()
            }

            // Set file type icon
            if (ivFileType.isVisible) {
                val iconRes = when (file.extension.lowercase()) {
                    "pdf" -> R.drawable.ic_pdf
                    "doc", "docx" -> R.drawable.ic_doc
                    "ppt", "pptx" -> R.drawable.ic_ppt
                    else -> R.drawable.ic_file
                }
                ivFileType.setImageResource(iconRes)
            }

            // Show preview if it's an image
            if (file.getMimeType().startsWith("image/")) {
                ivPreview.show()
                Glide.with(context)
                    .load(file)
                    .centerCrop()
                    .into(ivPreview)
            } else {
                ivPreview.hide()
            }
        }
    }

    private fun clearFile() {
        binding.apply {
            groupUpload.show()
            groupFileInfo.hide()
            ivPreview.hide()
            tvError.hide()
        }
    }

    private fun showError(message: String) {
        binding.apply {
            tvError.text = message
            tvError.show()
        }
    }

    fun setMaxFileSize(size: Int) {
        maxFileSize = size
    }

    fun setAllowedFileTypes(types: List<String>) {
        allowedFileTypes = types
    }

    fun setUploadButtonText(text: String) {
        binding.btnUpload.text = text
    }
}