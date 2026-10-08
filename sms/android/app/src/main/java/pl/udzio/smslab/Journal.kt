package pl.udzio.smslab

import android.content.Context
import androidx.room.*

@Entity(tableName = "tasks")
data class Task(
    @PrimaryKey val key: String,
    val messageId: String,
    val attempt: Int,
    val lease: String,
    val leaseUntil: Long,
    val provider: String,
    val recipient: String,
    val body: String,
    val stage: String = "CLAIMED",
    val started: Long = 0,
    val subscription: Int = -1,
    val created: Long = System.currentTimeMillis()
)
@Entity(tableName = "outbox", indices = [Index(value = ["eventId"], unique = true)])
data class Outbox(@PrimaryKey(autoGenerate = true) val sequence: Long = 0,
    val eventId: String, val messageId: String, val json: String, val state: String = "PENDING")
@Entity(tableName = "diagnostics")
data class Diagnostic(@PrimaryKey(autoGenerate = true) val id: Long = 0, val time: Long = System.currentTimeMillis(), val code: String)

@Dao
interface JournalDao {
    @Insert(onConflict = OnConflictStrategy.IGNORE) fun insert(task: Task): Long
    @Query("SELECT * FROM tasks WHERE `key`=:key") fun task(key: String): Task?
    @Query("SELECT * FROM tasks WHERE stage IN ('CLAIMED','PREPARED','DISPATCHED') ORDER BY created LIMIT 1") fun active(): Task?
    @Query("SELECT * FROM tasks ORDER BY created DESC LIMIT 50") fun recent(): List<Task>
    @Query("UPDATE tasks SET stage=:stage,started=:started,subscription=:subscription WHERE `key`=:key AND stage=:expected")
    fun advance(key: String, expected: String, stage: String, started: Long, subscription: Int): Int
    @Query("UPDATE tasks SET stage=:stage WHERE `key`=:key") fun stage(key: String, stage: String)
    @Query("UPDATE tasks SET recipient='',body='' WHERE `key`=:key") fun redact(key: String)
    @Query("UPDATE tasks SET recipient='',body='' WHERE created<:before") fun retention(before: Long)
    @Insert(onConflict = OnConflictStrategy.IGNORE) fun event(event: Outbox): Long
    @Query("SELECT * FROM outbox WHERE state='PENDING' ORDER BY sequence") fun pending(): List<Outbox>
    @Query("UPDATE outbox SET state=:state WHERE sequence=:id") fun eventState(id: Long, state: String)
    @Query("SELECT count(*) FROM outbox WHERE state='PENDING'") fun pendingCount(): Int
    @Insert fun diagnostic(diagnostic: Diagnostic)
    @Query("SELECT * FROM diagnostics ORDER BY id DESC LIMIT 50") fun diagnostics(): List<Diagnostic>
    @Query("DELETE FROM diagnostics WHERE id NOT IN (SELECT id FROM diagnostics ORDER BY id DESC LIMIT 50)") fun trimDiagnostics()
}

@Database(entities = [Task::class, Outbox::class, Diagnostic::class], version = 1, exportSchema = false)
abstract class Journal : RoomDatabase() {
    abstract fun dao(): JournalDao
    companion object {
        @Volatile private var instance: Journal? = null
        fun get(context: Context): Journal = instance ?: synchronized(this) {
            instance ?: Room.databaseBuilder(context.applicationContext, Journal::class.java, "sms-journal.db")
                .build().also { instance = it }
        }
    }
}
